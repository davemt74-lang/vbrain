<?php
declare(strict_types=1);

/**
 * Day-of local concierge for a trip.
 *
 * Browser/device coordinates are accepted only as in-memory inputs to refresh().
 * They are never written to the concierge tables, agent context, or collaboration
 * events. Persisted runs contain only a coarse anchor label plus public venue/event
 * results returned by configured travel providers.
 */
final class LocalConciergeService
{
    private const WINDOWS=['now','next_4_hours','tonight','today','tomorrow'];
    private const INTERESTS=['food','drinks','outdoors','culture','events','nightlife','family'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_local_concierge_runs')
            && db_table_exists('trip_local_concierge_actions');
    }

    public function access(int $userId,int $tripId): array
    {
        if($userId<1||$tripId<1)throw new OutOfBoundsException('Trip not found.');
        $stmt=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=? LIMIT 1');$stmt->execute([$tripId]);$ownerId=(int)($stmt->fetchColumn()?:0);
        if($ownerId<1)throw new OutOfBoundsException('Trip not found.');
        if($ownerId===$userId)return ['role'=>'owner','is_owner'=>true,'can_view'=>true,'can_add'=>true];
        if(!class_exists('TripCollaborationService'))throw new OutOfBoundsException('Trip not found.');
        $collab=(new TripCollaborationService($this->pdo))->access($userId,$tripId);if(!$collab||empty($collab['can_view']))throw new OutOfBoundsException('Trip not found.');
        return ['role'=>(string)$collab['role'],'is_owner'=>false,'can_view'=>true,'can_add'=>!empty($collab['can_plan'])];
    }

    public function refresh(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$this->access($userId,$tripId);$trip=$this->trip($tripId);
        $window=$this->window((string)($input['window']??'now'));$interests=$this->interests($input['interests']??[]);
        $mode=strtolower(trim((string)($input['anchor_mode']??'destination')))==='device'?'device':'destination';
        $lat=$this->floatOrNull($input['latitude']??null);$lng=$this->floatOrNull($input['longitude']??null);
        if($mode==='device'){
            if($lat===null||$lng===null||$lat < -90||$lat > 90||$lng < -180||$lng > 180)throw new InvalidArgumentException('Share your current location for this one refresh, or use the trip destination instead.');
            $anchorLabel='Current location';
        }else{
            $lat=$this->floatOrNull($trip['destination_latitude']??null);$lng=$this->floatOrNull($trip['destination_longitude']??null);$anchorLabel=(string)($trip['destination_name']?:'Trip destination');
        }

        // Temporary provider request only. Device coordinates are not copied into persistence below.
        $providerTrip=$trip;$providerTrip['user_id']=$userId;$providerTrip['destination_latitude']=$lat;$providerTrip['destination_longitude']=$lng;
        [$start,$end]=$this->windowDates($window);$providerTrip['start_date']=$start;$providerTrip['end_date']=$end;
        $providers=new LiveTravelDataProviderService($this->pdo);
        $places=$this->safeProvider(fn()=>$providers->places($providerTrip),'places');
        $events=$this->safeProvider(fn()=>$providers->events($providerTrip),'events');
        $weather=$this->safeProvider(fn()=>$providers->weather($providerTrip),'weather');
        $suggestions=$this->rankSuggestions($trip,$window,$interests,$places,$events,$weather);
        // Saved destination research is valid only when the trip destination is the active anchor.
        // Never pad a device-location run with recommendations from a potentially distant destination.
        if($mode==='destination'&&count($suggestions)<8)$suggestions=$this->appendSavedResearch($trip,$window,$interests,$suggestions,$weather);
        $suggestions=array_slice($suggestions,0,24);

        $health=[
            'places'=>$this->providerHealth($places),
            'events'=>$this->providerHealth($events),
            'weather'=>$this->providerHealth($weather),
        ];
        $weatherPublic=$this->weatherPublic($weather);
        $expires=(new DateTimeImmutable())->modify('+2 hours')->format('Y-m-d H:i:s');
        $stmt=$this->pdo->prepare('INSERT INTO trip_local_concierge_runs (user_id,dream_trip_id,anchor_mode,anchor_label,concierge_window,interests_json,weather_json,provider_health_json,suggestions_json,observed_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?)');
        $stmt->execute([$userId,$tripId,$mode,$anchorLabel,$window,$this->json($interests),$this->json($weatherPublic),$this->json($health),$this->json($suggestions),$expires]);
        return $this->run($userId,$tripId,(int)$this->pdo->lastInsertId())??throw new RuntimeException('Local concierge refresh could not be saved.');
    }

    public function latest(int $userId,int $tripId): ?array
    {
        if(!$this->ready())return null;$this->access($userId,$tripId);
        $stmt=$this->pdo->prepare('SELECT * FROM trip_local_concierge_runs WHERE user_id=? AND dream_trip_id=? ORDER BY observed_at DESC,id DESC LIMIT 1');$stmt->execute([$userId,$tripId]);$row=$stmt->fetch();
        return $row?$this->hydrateRun($row,$userId):null;
    }

    public function run(int $userId,int $tripId,int $runId): ?array
    {
        if(!$this->ready()||$runId<1)return null;$this->access($userId,$tripId);
        $stmt=$this->pdo->prepare('SELECT * FROM trip_local_concierge_runs WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$runId,$userId,$tripId]);$row=$stmt->fetch();
        return $row?$this->hydrateRun($row,$userId):null;
    }

    public function addSuggestion(int $userId,int $tripId,int $runId,string $suggestionKey,?string $date=null,?string $daypart=null): int
    {
        $this->requireReady();$access=$this->access($userId,$tripId);if(empty($access['can_add']))throw new DomainException('Only the trip owner or a Co-planner can add concierge suggestions to the shared itinerary.');
        $this->pdo->beginTransaction();
        try{
            // Lock the saved run so duplicate fast submissions serialize before an itinerary row is created.
            $lock=$this->pdo->prepare('SELECT id FROM trip_local_concierge_runs WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1 FOR UPDATE');$lock->execute([$runId,$userId,$tripId]);if(!$lock->fetchColumn())throw new OutOfBoundsException('Concierge refresh not found.');
            $run=$this->run($userId,$tripId,$runId);if(!$run)throw new OutOfBoundsException('Concierge refresh not found.');$suggestion=$this->findSuggestion($run,$suggestionKey);
            if(!$suggestion)throw new OutOfBoundsException('That concierge suggestion is no longer in this refresh.');
            if(!empty($suggestion['dismissed']))throw new DomainException('That concierge suggestion was dismissed from this refresh. Refresh or choose another suggestion.');
            if(!empty($suggestion['added'])||$this->hasAction($runId,$userId,$suggestionKey,'added'))throw new DomainException('That suggestion is already on the itinerary from this refresh.');
            $date=$this->dateOrNull($date)??$this->dateOrNull((string)($suggestion['date']??''));$daypart=$this->daypart($daypart)??$this->defaultDaypart((string)$run['concierge_window']);
            $notes=$this->clip(implode(' · ',array_filter([(string)($suggestion['reason']??''),(string)($suggestion['address']??''),(string)($suggestion['provider']??''),(string)($suggestion['url']??'')])),1000);
            $itemType=$this->itemType((string)($suggestion['category']??''),(string)($suggestion['kind']??''));
            $price=isset($suggestion['price'])&&is_numeric($suggestion['price'])?max(0,(float)$suggestion['price']):null;
            $collab=new TripCollaborationService($this->pdo);
            $itemId=$collab->addItem($userId,$tripId,['item_type'=>$itemType,'title'=>(string)$suggestion['title'],'price'=>$price,'notes'=>$notes,'scheduled_date'=>$date,'daypart'=>$daypart]);
            $this->recordAction($runId,$userId,$tripId,$suggestionKey,'added',$itemId);
            $this->pdo->commit();return $itemId;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function dismissSuggestion(int $userId,int $tripId,int $runId,string $suggestionKey): void
    {
        $this->requireReady();$this->access($userId,$tripId);$run=$this->run($userId,$tripId,$runId);if(!$run||!$this->findSuggestion($run,$suggestionKey))throw new OutOfBoundsException('Concierge suggestion not found.');
        $this->recordAction($runId,$userId,$tripId,$suggestionKey,'dismissed',null);
    }

    public function agentContext(int $userId,int $limit=1): string
    {
        if(!$this->ready())return '';$limit=max(1,min(3,$limit));
        $stmt=$this->pdo->prepare("SELECT r.id,r.dream_trip_id,r.anchor_mode,r.anchor_label,r.concierge_window,r.suggestions_json,r.weather_json,r.observed_at,t.name trip_name
            FROM trip_local_concierge_runs r JOIN dream_trips t ON t.id=r.dream_trip_id
            WHERE r.user_id=? ORDER BY r.observed_at DESC,r.id DESC LIMIT $limit");$stmt->execute([$userId]);$rows=$stmt->fetchAll()?:[];if(!$rows)return '';
        $parts=[];foreach($rows as $row){$suggestions=json_decode((string)($row['suggestions_json']??''),true)?:[];$bits=[];foreach(array_slice($suggestions,0,5) as $s){if(!is_array($s))continue;$bits[]=(string)($s['title']??'Local option').' ['.(string)($s['category']??'local').']';}$parts[]=(string)$row['trip_name'].' · '.str_replace('_',' ',(string)$row['concierge_window']).' · '.implode(', ',$bits);}
        return 'LOCAL CONCIERGE STATE (saved results only; no provider or location refresh from chat): '.implode('; ',$parts).'. Exact device coordinates are never stored in this concierge ledger. Treat results as time-sensitive public place/event suggestions, not reservations. Only the owner or a Co-planner may add a suggestion to the itinerary; Traveler and Viewer roles cannot mutate the shared plan.';
    }

    public function fallback(int $userId): ?array
    {
        if(!$this->ready())return null;
        $stmt=$this->pdo->prepare('SELECT id,dream_trip_id FROM trip_local_concierge_runs WHERE user_id=? ORDER BY observed_at DESC,id DESC LIMIT 1');$stmt->execute([$userId]);$row=$stmt->fetch();if(!$row)return null;
        try{return $this->run($userId,(int)$row['dream_trip_id'],(int)$row['id']);}catch(Throwable){return null;}
    }

    private function hydrateRun(array $row,int $userId): array
    {
        $row['id']=(int)$row['id'];$row['trip_id']=(int)$row['dream_trip_id'];$row['interests']=json_decode((string)($row['interests_json']??''),true)?:[];$row['weather']=json_decode((string)($row['weather_json']??''),true)?:[];$row['provider_health']=json_decode((string)($row['provider_health_json']??''),true)?:[];$suggestions=json_decode((string)($row['suggestions_json']??''),true)?:[];
        $actions=$this->actionsForRun((int)$row['id'],$userId);foreach($suggestions as &$s){$key=(string)($s['key']??'');$s['added']=isset($actions[$key]['added']);$s['dismissed']=isset($actions[$key]['dismissed']);}unset($s);$row['suggestions']=$suggestions;$row['expired']=strtotime((string)$row['expires_at'])<=time();unset($row['interests_json'],$row['weather_json'],$row['provider_health_json'],$row['suggestions_json']);return $row;
    }

    private function rankSuggestions(array $trip,string $window,array $interests,array $places,array $events,array $weather): array
    {
        $rows=[];$wet=$this->wetWeather($weather);$eventDate=$this->windowTargetDate($window);
        foreach((array)($places['items']??[]) as $place){if(!is_array($place))continue;$category=$this->placeCategory((string)($place['category']??$place['type']??''));$score=55;$rating=$this->floatOrNull($place['rating']??null);$reviews=max(0,(int)($place['review_count']??0));if($rating!==null)$score+=(int)round(max(0,$rating-3.5)*12);$score+=min(10,(int)floor(log10(max(1,$reviews))*3));if(in_array($category,$interests,true))$score+=10;if($wet&&$category==='outdoors')$score-=18;if($wet&&in_array($category,['culture','food'],true))$score+=7;if($window==='tonight'&&in_array($category,['drinks','nightlife','food'],true))$score+=8;
            $reason=$this->placeReason($category,$rating,$reviews,$wet,$window);$rows[]=$this->suggestion('place',(string)($place['id']??sha1(json_encode($place))),$place['name']??'Local place',$category,$score,$reason,(string)($place['address']??''),(string)($place['website_url']??$place['maps_url']??''),(string)($places['provider']??'Google Places'),null,null,$rating,$reviews,null);
        }
        foreach((array)($events['items']??[]) as $event){if(!is_array($event))continue;$date=(string)($event['date']??'');if($eventDate!==''&&$date!==''&&$date!==$eventDate&&$window!=='next_4_hours')continue;$category='events';$score=70+(in_array('events',$interests,true)?10:0);$time=(string)($event['time']??'');if($window==='tonight'&&$time!==''&&$time>='17:00')$score+=8;$reason=trim(implode(' · ',array_filter(['Live event'.($date?' '.$this->dayLabel($date):''),(string)($event['venue']??''),(string)($event['category']??'')])));$price=isset($event['price_min'])&&is_numeric($event['price_min'])?(float)$event['price_min']:null;$rows[]=$this->suggestion('event',(string)($event['id']??sha1(json_encode($event))),$event['name']??'Local event',$category,$score,$reason,trim((string)($event['venue']??'').((!empty($event['address']))?' · '.(string)$event['address']:'')),(string)($event['url']??''),(string)($events['provider']??'Ticketmaster'),$date,$time,null,0,$price);
        }
        usort($rows,static fn($a,$b)=>(int)$b['score']<=>(int)$a['score']);return $this->dedupe($rows);
    }

    private function appendSavedResearch(array $trip,string $window,array $interests,array $existing,array $weather): array
    {
        $catalogId=(int)($trip['destination_catalog_id']??0);if($catalogId<1||!class_exists('DestinationResearchService'))return $existing;
        try{$report=(new DestinationResearchService($this->pdo))->latestForDestination($catalogId);}catch(Throwable){$report=null;}if(!$report)return $existing;
        $known=[];foreach($existing as $s)$known[strtolower((string)($s['title']??''))]=1;$rows=$existing;$wet=$this->wetWeather($weather);
        foreach((array)($report['restaurants']??[]) as $r){if(!is_array($r))continue;$title=trim((string)($r['name']??''));if($title===''||isset($known[strtolower($title)]))continue;$rating=$this->floatOrNull($r['rating']??null);$reviews=(int)($r['review_count']??0);$score=52+(in_array('food',$interests,true)?8:0)+($wet?4:0);$rows[]=$this->suggestion('saved_research','restaurant:'.sha1($title),$title,'food',$score,'Saved destination research · recheck hours and availability before going.',trim((string)($r['area']??'')),(string)($r['website_url']??$r['source_url']??''),'Saved destination research',null,null,$rating,$reviews,null);$known[strtolower($title)]=1;}
        $dayTrips=$report['day_trips']??[];if(isset($dayTrips['day_trips']))$dayTrips=$dayTrips['day_trips'];foreach((array)$dayTrips as $r){if(!is_array($r))continue;$title=trim((string)($r['name']??''));if($title===''||isset($known[strtolower($title)]))continue;$score=48+(in_array('outdoors',$interests,true)?7:0)-($wet?10:0);$rows[]=$this->suggestion('saved_research','daytrip:'.sha1($title),$title,'outdoors',$score,'Saved destination research · verify current hours, conditions, and travel time.',(string)($r['distance']??''),(string)($r['website_url']??$r['source_url']??''),'Saved destination research',null,null,null,0,null);$known[strtolower($title)]=1;}
        foreach((array)($report['shows']??[]) as $r){if(!is_array($r))continue;$title=trim((string)($r['name']??''));if($title===''||isset($known[strtolower($title)]))continue;$date='';if(!empty($r['starts_at'])){$ts=strtotime((string)$r['starts_at']);if($ts)$date=date('Y-m-d',$ts);}$rows[]=$this->suggestion('saved_research','show:'.sha1($title),$title,'events',50+(in_array('events',$interests,true)?8:0),'Saved destination research · verify event status and ticket availability.',(string)($r['venue']??''),(string)($r['ticket_url']??$r['source_url']??''),'Saved destination research',$date,null,null,0,null);$known[strtolower($title)]=1;}
        usort($rows,static fn($a,$b)=>(int)$b['score']<=>(int)$a['score']);return $this->dedupe($rows);
    }

    private function suggestion(string $kind,string $externalId,mixed $title,string $category,int $score,string $reason,string $address,string $url,string $provider,?string $date,?string $time,?float $rating,int $reviews,?float $price): array
    {
        $title=$this->clip((string)$title,180);$url=$this->safeUrl($url);$key=hash('sha256',$kind.'|'.$externalId.'|'.strtolower($title));
        return ['key'=>$key,'kind'=>$kind,'external_id'=>$this->clip($externalId,180),'title'=>$title,'category'=>$category,'score'=>max(0,min(100,$score)),'reason'=>$this->clip($reason,500),'address'=>$this->clip($address,300),'url'=>$url,'provider'=>$this->clip($provider,120),'date'=>$this->dateOrNull((string)$date),'time'=>$this->timeOrNull((string)$time),'rating'=>$rating,'review_count'=>$reviews,'price'=>$price];
    }

    private function findSuggestion(array $run,string $key): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$key))return null;foreach((array)($run['suggestions']??[]) as $s)if(is_array($s)&&hash_equals((string)($s['key']??''),$key))return $s;return null;
    }

    private function actionsForRun(int $runId,int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT suggestion_key,action_type,dream_trip_item_id FROM trip_local_concierge_actions WHERE run_id=? AND user_id=? ORDER BY id');$stmt->execute([$runId,$userId]);$out=[];foreach($stmt->fetchAll()?:[] as $r)$out[(string)$r['suggestion_key']][(string)$r['action_type']]=$r;return $out;
    }

    private function hasAction(int $runId,int $userId,string $key,string $type): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM trip_local_concierge_actions WHERE run_id=? AND user_id=? AND suggestion_key=? AND action_type=? LIMIT 1');$stmt->execute([$runId,$userId,$key,$type]);return (bool)$stmt->fetchColumn();
    }

    private function recordAction(int $runId,int $userId,int $tripId,string $key,string $type,?int $itemId): void
    {
        $this->pdo->prepare('INSERT INTO trip_local_concierge_actions (run_id,user_id,dream_trip_id,suggestion_key,action_type,dream_trip_item_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE dream_trip_item_id=COALESCE(VALUES(dream_trip_item_id),dream_trip_item_id)')->execute([$runId,$userId,$tripId,$key,$type,$itemId]);
    }

    private function trip(int $tripId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,user_id,name,start_date,end_date,travelers,currency,destination_catalog_id,destination_latitude,destination_longitude,destination_iata,metadata_json FROM dream_trips WHERE id=? LIMIT 1');$stmt->execute([$tripId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Trip not found.');$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=(string)($meta['destination_name']??$row['name']??'Trip destination');unset($row['metadata_json']);return $row;
    }

    private function safeProvider(callable $fn,string $type): array
    {
        try{$r=$fn();return is_array($r)?$r:['ok'=>false,'data_type'=>$type,'error'=>'Provider returned no usable data.'];}catch(Throwable $e){return ['ok'=>false,'data_type'=>$type,'provider'=>'Vacation Brain','error'=>$this->clip($e->getMessage(),500),'observed_at'=>gmdate('c')];}
    }

    private function providerHealth(array $r): array{return ['ok'=>!empty($r['ok']),'provider'=>(string)($r['provider']??$r['source']??'Unavailable'),'observed_at'=>(string)($r['observed_at']??gmdate('c')),'error'=>!empty($r['ok'])?null:$this->clip((string)($r['error']??'Unavailable'),300)];}
    private function weatherPublic(array $w): array{$day=is_array($w['days'][0]??null)?$w['days'][0]:[];return ['ok'=>!empty($w['ok']),'provider'=>(string)($w['provider']??''),'observed_at'=>(string)($w['observed_at']??''),'date'=>(string)($day['date']??''),'high'=>$day['high']??null,'low'=>$day['low']??null,'precip_probability'=>$day['precip_probability']??null,'conditions'=>(string)($day['conditions']??''),'alerts'=>array_slice((array)($w['alerts']??[]),0,3)];}
    private function wetWeather(array $w): bool{$day=is_array($w['days'][0]??null)?$w['days'][0]:[];$rain=$this->floatOrNull($day['precip_probability']??null);$conditions=strtolower((string)($day['conditions']??''));return ($rain!==null&&$rain>=50)||str_contains($conditions,'rain')||str_contains($conditions,'storm');}

    private function placeCategory(string $value): string{$v=strtolower($value);if(str_contains($v,'restaurant')||str_contains($v,'cafe')||str_contains($v,'food'))return 'food';if(str_contains($v,'bar')||str_contains($v,'drink'))return 'drinks';if(str_contains($v,'night'))return 'nightlife';if(str_contains($v,'museum')||str_contains($v,'art')||str_contains($v,'culture'))return 'culture';if(str_contains($v,'park')||str_contains($v,'outdoor')||str_contains($v,'attraction'))return 'outdoors';return 'culture';}
    private function placeReason(string $category,?float $rating,int $reviews,bool $wet,string $window): string{$bits=[];$bits[]=match($category){'food'=>'Good local food candidate','drinks'=>'Drinks nearby','nightlife'=>'Nightlife option','outdoors'=>$wet?'Outdoor option — weather may be a factor':'Good outdoor option','culture'=>$wet?'Strong indoor backup':'Local culture / attraction',default=>'Local option'};if($rating!==null)$bits[]=number_format($rating,1).'★'.($reviews>0?' from '.number_format($reviews).' reviews':'');if($window==='tonight')$bits[]='ranked for tonight';return implode(' · ',$bits).'. Verify current hours before heading over.';}
    private function itemType(string $category,string $kind): string{if($category==='food')return 'food';if(in_array($category,['drinks','nightlife','events'],true)||$kind==='event')return 'experience';return 'activity';}
    private function defaultDaypart(string $window): string{return match($window){'tonight'=>'evening','now','next_4_hours'=>'anytime',default=>'anytime'};}
    private function windowTargetDate(string $window): string{return $window==='tomorrow'?date('Y-m-d',strtotime('+1 day')):date('Y-m-d');}
    private function windowDates(string $window): array{$start=$this->windowTargetDate($window);return [$start,$start];}
    private function window(string $v): string{$v=strtolower(trim($v));return in_array($v,self::WINDOWS,true)?$v:'now';}
    private function interests(mixed $value): array{$values=is_array($value)?$value:explode(',',(string)$value);$out=[];foreach($values as $v){$v=strtolower(trim((string)$v));if(in_array($v,self::INTERESTS,true))$out[$v]=1;}return array_keys($out?:array_fill_keys(['food','outdoors','culture','events'],1));}
    private function daypart(?string $v): ?string{$v=strtolower(trim((string)$v));return in_array($v,['morning','afternoon','evening','anytime'],true)?$v:null;}
    private function dateOrNull(string $v): ?string{$v=trim($v);if($v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:null;}
    private function timeOrNull(string $v): ?string{$v=trim($v);return preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$v)?substr($v,0,5):null;}
    private function floatOrNull(mixed $v): ?float{return $v!==null&&$v!==''&&is_numeric($v)?(float)$v:null;}
    private function safeUrl(string $v): string{$v=trim($v);return $v!==''&&preg_match('#^https://#i',$v)?$this->clip($v,1500):'';}
    private function dayLabel(string $date): string{$ts=strtotime($date);return $ts?date('D M j',$ts):$date;}
    private function dedupe(array $rows): array{$seen=[];$out=[];foreach($rows as $r){$k=strtolower((string)($r['title']??''));if($k===''||isset($seen[$k]))continue;$seen[$k]=1;$out[]=$r;}return $out;}
    private function json(mixed $v): string{return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'[]';}
    private function clip(string $v,int $max): string{$v=trim(preg_replace('/\s+/',' ',$v)??$v);return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade to enable Destination & Local Concierge.');}
}
