<?php
declare(strict_types=1);

final class TripIntelligenceService
{
    private TravelDataProviderService $providers;

    public function __construct(private PDO $pdo)
    {
        $this->providers=new TravelDataProviderService($pdo);
    }

    public function ready(): bool
    {
        return db_table_exists('trip_intelligence_snapshots')
            && db_column_exists('dream_trips','origin_name')
            && db_column_exists('dream_trips','destination_catalog_id')
            && db_column_exists('dream_trip_items','scheduled_date');
    }

    public function providerStatus(): array
    {
        return $this->providers->providerStatus();
    }

    public function dashboard(int $userId,int $tripId): array
    {
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);
        if(!$trip)throw new RuntimeException('Trip not found.');
        $trip=$this->resolveDestination($userId,$trip);
        $snapshots=[];$stale=[];
        foreach(['weather','events','places','flights'] as $type){
            $snapshots[$type]=$this->latest($userId,$tripId,$type);
            if(!$snapshots[$type]||$this->isExpired($snapshots[$type]))$stale[]=$type;
        }
        $weather=$snapshots['weather']['payload']??[];$events=$snapshots['events']['payload']??[];$places=$snapshots['places']['payload']??[];$flights=$snapshots['flights']['payload']??[];
        return [
            'trip'=>$trip,
            'snapshots'=>$snapshots,
            'stale'=>$stale,
            'providers'=>$this->providerStatus(),
            'opportunities'=>$this->opportunities($weather,$events,$places),
            'budget'=>$this->budget($trip,$flights),
        ];
    }

    public function refresh(int $userId,int $tripId,array $types,bool $force=true): array
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before refreshing trip intelligence.');
        $dreams=new DreamService($this->pdo);$trip=$dreams->get($userId,$tripId,false);if(!$trip)throw new RuntimeException('Trip not found.');$trip=$this->resolveDestination($userId,$trip);
        $allowed=['weather','events','places','flights'];$types=array_values(array_unique(array_filter(array_map('strval',$types),static fn($v)=>in_array($v,$allowed,true))));if(!$types)$types=$allowed;
        $out=[];
        foreach($types as $type){
            $existing=$this->latest($userId,$tripId,$type);
            if(!$force&&$existing&&!$this->isExpired($existing)){$out[$type]=$existing;continue;}
            try{
                $payload=match($type){
                    'weather'=>$this->providers->weather($trip),
                    'events'=>$this->providers->events($trip),
                    'places'=>$this->providers->places($trip),
                    'flights'=>$this->providers->flights($trip),
                    default=>[],
                };
                if($type==='weather'&&!empty($payload['ok'])){
                    $lat=$payload['latitude']??null;$lng=$payload['longitude']??null;
                    if($lat!==null&&$lng!==null){$this->pdo->prepare('UPDATE dream_trips SET destination_latitude=?,destination_longitude=? WHERE id=? AND user_id=?')->execute([$lat,$lng,$tripId,$userId]);$trip['destination_latitude']=$lat;$trip['destination_longitude']=$lng;}
                }
                if($type==='flights'&&!empty($payload['ok'])){
                    $this->pdo->prepare('UPDATE dream_trips SET origin_iata=COALESCE(NULLIF(origin_iata,\'\'),?),destination_iata=COALESCE(NULLIF(destination_iata,\'\'),?) WHERE id=? AND user_id=?')->execute([$payload['origin_iata']??null,$payload['destination_iata']??null,$tripId,$userId]);
                }
                $out[$type]=$this->store($userId,$tripId,$type,$payload);
            }catch(Throwable $e){
                $payload=['ok'=>false,'data_type'=>$type,'provider'=>'Vacation Brain','source_status'=>'failed','observed_at'=>gmdate('c'),'expires_in'=>900,'error'=>$this->clip($e->getMessage(),500)];$out[$type]=$this->store($userId,$tripId,$type,$payload);
            }
        }
        $this->pdo->prepare('UPDATE dream_trips SET intelligence_refreshed_at=NOW() WHERE id=? AND user_id=?')->execute([$tripId,$userId]);
        return $out;
    }

    public function addFromSnapshot(int $userId,int $tripId,string $type,string $externalId,?string $scheduledDate=null,?string $daypart=null): void
    {
        if(!in_array($type,['events','places'],true))throw new InvalidArgumentException('That intelligence item cannot be added to the trip.');
        $snapshot=$this->latest($userId,$tripId,$type);if(!$snapshot)throw new RuntimeException('Refresh trip intelligence first.');$items=$snapshot['payload']['items']??[];$found=null;
        foreach($items as $item){if((string)($item['id']??'')===$externalId){$found=$item;break;}}
        if(!$found)throw new RuntimeException('That trip opportunity is no longer in the current data snapshot.');
        $itemType=$type==='events'?'experience':$this->dreamItemType((string)($found['category']??$found['type']??''));
        $price=null;if($type==='events'&&isset($found['price_min'])&&$found['price_min']!==null)$price=(float)$found['price_min'];
        $url=(string)($found['url']??$found['website_url']??$found['maps_url']??'');
        (new DreamService($this->pdo))->addItem($userId,$tripId,[
            'item_type'=>$itemType,
            'title'=>(string)($found['name']??'Trip idea'),
            'price'=>$price,
            'notes'=>$this->snapshotNote($type,$found),
            'scheduled_date'=>$scheduledDate??($found['date']??null),
            'daypart'=>$daypart,
            'source_provider'=>(string)($snapshot['provider']??''),
            'source_external_id'=>$externalId,
            'source_url'=>$url,
        ]);
    }

    public function scheduleItem(int $userId,int $tripId,int $itemId,?string $date,?string $daypart): void
    {
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new RuntimeException('Trip not found.');
        $date=$date&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)?$date:null;$daypart=in_array($daypart,['morning','afternoon','evening','anytime'],true)?$daypart:null;
        $stmt=$this->pdo->prepare('UPDATE dream_trip_items SET scheduled_date=?,daypart=? WHERE id=? AND dream_trip_id=?');$stmt->execute([$date,$daypart,$itemId,$tripId]);
    }

    private function resolveDestination(int $userId,array $trip): array
    {
        if(!$this->ready())return $trip;$catalogId=(int)($trip['destination_catalog_id']??0);$destinationName=trim((string)($trip['destination_name']??''));$catalog=null;
        if($catalogId>0){$stmt=$this->pdo->prepare('SELECT id,name,city,region,country,latitude,longitude FROM destination_catalog WHERE id=? LIMIT 1');$stmt->execute([$catalogId]);$catalog=$stmt->fetch()?:null;}
        if(!$catalog&&$destinationName!==''){$stmt=$this->pdo->prepare("SELECT id,name,city,region,country,latitude,longitude FROM destination_catalog WHERE LOWER(name)=LOWER(?) AND status='active' ORDER BY publication_status='published' DESC LIMIT 1");$stmt->execute([$destinationName]);$catalog=$stmt->fetch()?:null;}
        if($catalog){$catalogId=(int)$catalog['id'];$lat=$trip['destination_latitude']??$catalog['latitude'];$lng=$trip['destination_longitude']??$catalog['longitude'];$this->pdo->prepare('UPDATE dream_trips SET destination_catalog_id=?,destination_latitude=COALESCE(destination_latitude,?),destination_longitude=COALESCE(destination_longitude,?) WHERE id=? AND user_id=?')->execute([$catalogId,$catalog['latitude'],$catalog['longitude'],(int)$trip['id'],$userId]);$trip['destination_catalog_id']=$catalogId;$trip['destination_latitude']=$lat;$trip['destination_longitude']=$lng;$trip['destination_catalog']=$catalog;}
        return $trip;
    }

    private function latest(int $userId,int $tripId,string $type): ?array
    {
        if(!$this->ready())return null;$stmt=$this->pdo->prepare('SELECT * FROM trip_intelligence_snapshots WHERE dream_trip_id=? AND user_id=? AND data_type=? ORDER BY observed_at DESC,id DESC LIMIT 1');$stmt->execute([$tripId,$userId,$type]);$row=$stmt->fetch();if(!$row)return null;$payload=json_decode((string)($row['payload_json']??''),true);$row['payload']=is_array($payload)?$payload:[];unset($row['payload_json']);return $row;
    }

    private function store(int $userId,int $tripId,string $type,array $payload): array
    {
        $provider=(string)($payload['source']??$payload['provider']??'vacation_brain');$queryHash=hash('sha256',json_encode(['type'=>$type,'trip'=>$tripId,'provider'=>$provider,'date'=>date('Y-m-d-H')],JSON_UNESCAPED_SLASHES));$ok=!empty($payload['ok']);$ttl=max(300,min(604800,(int)($payload['expires_in']??3600)));$expires=(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');$error=$ok?null:$this->clip((string)($payload['error']??'Provider unavailable.'),500);
        $stmt=$this->pdo->prepare('INSERT INTO trip_intelligence_snapshots (dream_trip_id,user_id,provider,data_type,query_hash,payload_json,source_status,error_message,observed_at,expires_at) VALUES (?,?,?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),source_status=VALUES(source_status),error_message=VALUES(error_message),observed_at=NOW(),expires_at=VALUES(expires_at),updated_at=NOW()');$stmt->execute([$tripId,$userId,$provider,$type,$queryHash,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$ok?'success':'failed',$error,$expires]);return $this->latest($userId,$tripId,$type)??[];
    }

    private function isExpired(array $snapshot): bool
    {
        $expires=(string)($snapshot['expires_at']??'');return $expires===''||strtotime($expires)<=time();
    }

    private function opportunities(array $weather,array $events,array $places): array
    {
        $out=[];$days=$weather['days']??[];
        foreach(array_slice($days,0,10) as $day){$date=(string)($day['date']??'');$high=$day['high']??null;$rain=$day['precip_probability']??null;$wind=$day['wind']??null;$conditions=(string)($day['conditions']??'');
            if($high!==null&&$rain!==null&&(float)$high>=74&&(float)$rain<=25){$out[]=['kind'=>'weather','date'=>$date,'title'=>'Best outdoor / pool window','reason'=>$this->dayLabel($date).' looks warm with only '.round((float)$rain).'% precipitation risk.','score'=>92];}
            elseif($high!==null&&$rain!==null&&(float)$high>=55&&(float)$high<=84&&(float)$rain<=30){$out[]=['kind'=>'weather','date'=>$date,'title'=>'Good walking / exploring day','reason'=>$this->dayLabel($date).' is forecast around '.round((float)$high).'° with manageable rain risk.','score'=>84];}
            if($rain!==null&&(float)$rain>=55){$out[]=['kind'=>'weather','date'=>$date,'title'=>'Build an indoor backup','reason'=>$this->dayLabel($date).' has about '.round((float)$rain).'% precipitation probability. Save museums, food or indoor events for this window.','score'=>88];}
            if($wind!==null&&is_numeric($wind)&&(float)$wind>=25){$out[]=['kind'=>'weather','date'=>$date,'title'=>'Wind could change the plan','reason'=>$this->dayLabel($date).' may be breezy ('.round((float)$wind).' mph). Recheck boating and exposed outdoor plans.','score'=>75];}
        }
        foreach(array_slice($events['items']??[],0,4) as $event){$out[]=['kind'=>'event','date'=>(string)($event['date']??''),'title'=>(string)($event['name']??'Local event'),'reason'=>trim(implode(' · ',array_filter([(string)($event['venue']??''),(string)($event['category']??'')]))),'score'=>82];}
        foreach(array_slice($places['items']??[],0,4) as $place){if(($place['rating']??0)<4.4)continue;$out[]=['kind'=>'place','date'=>'','title'=>(string)($place['name']??'Local favorite'),'reason'=>trim((string)($place['category']??'Local')).(($place['rating']??null)!==null?' · '.number_format((float)$place['rating'],1).'★':''),'score'=>78+(int)min(10,round(((float)($place['rating']??4.4)-4.4)*10))];}
        usort($out,static fn($a,$b)=>($b['score']??0)<=>($a['score']??0));$seen=[];$final=[];foreach($out as $row){$key=strtolower((string)$row['title'].'|'.(string)$row['date']);if(isset($seen[$key]))continue;$seen[$key]=true;$final[]=$row;if(count($final)>=8)break;}return $final;
    }

    private function budget(array $trip,array $flights): array
    {
        $stmt=$this->pdo->prepare('SELECT item_type,COALESCE(SUM(price),0) total FROM dream_trip_items WHERE dream_trip_id=? GROUP BY item_type');$stmt->execute([(int)$trip['id']]);$byType=[];$committed=0.0;foreach($stmt->fetchAll()?:[] as $row){$byType[(string)$row['item_type']]=(float)$row['total'];$committed+=(float)$row['total'];}
        $travelers=max(1,(int)($trip['travelers']??1));$flightEstimate=!empty($flights['ok'])&&isset($flights['min_price'])&&$flights['min_price']!==null?(float)$flights['min_price']*$travelers:null;$target=$trip['target_budget']!==null?(float)$trip['target_budget']:null;$projected=$committed+($flightEstimate??0);$remaining=$target!==null?$target-$projected:null;
        return ['target'=>$target,'committed'=>$committed,'by_type'=>$byType,'flight_estimate'=>$flightEstimate,'projected'=>$projected,'remaining'=>$remaining,'currency'=>(string)($trip['currency']??'USD')];
    }

    private function dreamItemType(string $category): string
    {
        $c=strtolower($category);if(str_contains($c,'eat')||str_contains($c,'restaurant')||str_contains($c,'cafe'))return 'food';if(str_contains($c,'drink')||str_contains($c,'night'))return 'experience';return 'activity';
    }

    private function snapshotNote(string $type,array $item): string
    {
        if($type==='events')return trim(implode(' · ',array_filter([(string)($item['date']??''),(string)($item['time']??''),(string)($item['venue']??''),(string)($item['category']??'')])));return trim(implode(' · ',array_filter([(string)($item['category']??''),(string)($item['address']??''),isset($item['rating'])&&$item['rating']!==null?number_format((float)$item['rating'],1).'★':'' ])));
    }

    private function dayLabel(string $date): string
    {
        if($date==='')return 'That day';$ts=strtotime($date);return $ts?date('l, M j',$ts):$date;
    }

    private function clip(string $value,int $max): string
    {
        return function_exists('mb_substr')?mb_substr(trim($value),0,$max):substr(trim($value),0,$max);
    }
}
