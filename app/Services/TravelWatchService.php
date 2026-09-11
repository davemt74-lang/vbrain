<?php
declare(strict_types=1);

final class TravelWatchService
{
    private const TYPES=['weather','flights','events','places'];
    private const INTERVALS=[60,180,360,720,1440];
    private const MAX_ACTIVE_WATCHES=25;

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('travel_watches')
            && db_table_exists('travel_watch_snapshots')
            && db_table_exists('travel_watch_events');
    }

    public static function tripKey(int $tripId): string
    {
        return 'trip:'.max(0,$tripId);
    }

    public static function destinationKey(int $catalogId,string $name='',mixed $lat=null,mixed $lng=null): string
    {
        if($catalogId>0)return 'destination:'.$catalogId;
        $normalized=strtolower(trim(preg_replace('/\s+/',' ',$name)??$name));
        $coords=(is_numeric($lat)&&is_numeric($lng))?round((float)$lat,4).','.round((float)$lng,4):'';
        return 'place:'.substr(hash('sha256',$normalized.'|'.$coords),0,40);
    }

    public function listForUser(int $userId): array
    {
        if(!$this->ready())return [];
        $stmt=$this->pdo->prepare('SELECT * FROM travel_watches WHERE user_id=? ORDER BY is_active DESC,updated_at DESC,id DESC');
        $stmt->execute([$userId]);return $stmt->fetchAll()?:[];
    }

    public function watchedKeys(int $userId): array
    {
        if(!$this->ready())return [];
        $stmt=$this->pdo->prepare('SELECT id,target_key,target_type,dream_trip_id,destination_catalog_id FROM travel_watches WHERE user_id=? AND is_active=1');
        $stmt->execute([$userId]);$out=[];
        foreach($stmt->fetchAll()?:[] as $row)$out[(string)$row['target_key']]=$row;
        return $out;
    }

    public function recentEvents(int $userId,int $limit=40): array
    {
        if(!$this->ready())return [];$limit=max(1,min(100,$limit));
        $stmt=$this->pdo->prepare("SELECT e.*,w.destination_name,w.target_type,w.dream_trip_id,w.destination_catalog_id FROM travel_watch_events e JOIN travel_watches w ON w.id=e.watch_id WHERE e.user_id=? ORDER BY e.created_at DESC,e.id DESC LIMIT $limit");
        $stmt->execute([$userId]);$rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row){$row['metadata']=json_decode((string)($row['metadata_json']??''),true)?:[];unset($row['metadata_json']);}unset($row);return $rows;
    }

    public function saveTrip(int $userId,int $tripId,array $input=[]): array
    {
        $this->requireReady();$trip=(new DreamService($this->pdo))->get($userId,$tripId,false);
        if(!$trip)throw new RuntimeException('Trip not found.');
        $name=trim((string)($trip['destination_name']??''))?:trim((string)($trip['name']??'Trip'));
        $hasRoute=trim((string)($trip['origin_iata']??$trip['origin_name']??''))!=='';
        $targetKey=self::tripKey($tripId);$this->assertCapacity($userId,$targetKey);
        return $this->upsert([
            'user_id'=>$userId,'target_key'=>$targetKey,'target_type'=>'trip','dream_trip_id'=>$tripId,
            'destination_catalog_id'=>(int)($trip['destination_catalog_id']??0)?:null,'destination_name'=>$name,
            'destination_latitude'=>$this->floatOrNull($trip['destination_latitude']??null),'destination_longitude'=>$this->floatOrNull($trip['destination_longitude']??null),
            'watch_weather'=>$this->boolInput($input,'watch_weather',true),'watch_flights'=>$hasRoute&&$this->boolInput($input,'watch_flights',true),
            'watch_events'=>$this->boolInput($input,'watch_events',true),'watch_places'=>$this->boolInput($input,'watch_places',true),
            'interval_minutes'=>$this->interval($input['interval_minutes']??360),'is_active'=>$this->boolInput($input,'is_active',true),
        ]);
    }

    public function saveDestination(int $userId,int $catalogId,string $name,mixed $lat=null,mixed $lng=null,array $input=[]): array
    {
        $this->requireReady();[$catalogId,$name,$lat,$lng]=$this->resolveDestinationTarget($catalogId,$name,$lat,$lng);
        if($name==='')throw new InvalidArgumentException('Destination name is required.');
        $targetKey=self::destinationKey($catalogId,$name,$lat,$lng);$this->assertCapacity($userId,$targetKey);
        return $this->upsert([
            'user_id'=>$userId,'target_key'=>$targetKey,'target_type'=>'destination','dream_trip_id'=>null,
            'destination_catalog_id'=>$catalogId?:null,'destination_name'=>$name,'destination_latitude'=>$this->floatOrNull($lat),'destination_longitude'=>$this->floatOrNull($lng),
            'watch_weather'=>$this->boolInput($input,'watch_weather',true),'watch_flights'=>false,'watch_events'=>$this->boolInput($input,'watch_events',true),
            'watch_places'=>$this->boolInput($input,'watch_places',true),'interval_minutes'=>$this->interval($input['interval_minutes']??360),'is_active'=>$this->boolInput($input,'is_active',true),
        ]);
    }

    public function toggleDestination(int $userId,int $catalogId,string $name,mixed $lat=null,mixed $lng=null): array
    {
        $this->requireReady();[$catalogId,$name,$lat,$lng]=$this->resolveDestinationTarget($catalogId,$name,$lat,$lng);$key=self::destinationKey($catalogId,$name,$lat,$lng);
        $stmt=$this->pdo->prepare('SELECT * FROM travel_watches WHERE user_id=? AND target_key=? LIMIT 1');$stmt->execute([$userId,$key]);$existing=$stmt->fetch();
        if($existing&&((int)$existing['is_active'])===1){$this->pdo->prepare('UPDATE travel_watches SET is_active=0,next_check_at=NULL,last_status=\'paused\' WHERE id=? AND user_id=?')->execute([(int)$existing['id'],$userId]);return ['active'=>false,'watch_id'=>(int)$existing['id'],'target_key'=>$key];}
        $this->assertCapacity($userId,$key);$watch=$this->saveDestination($userId,$catalogId,$name,$lat,$lng,['is_active'=>1]);return ['active'=>true,'watch_id'=>(int)$watch['id'],'target_key'=>$key];
    }

    public function updateWatch(int $userId,int $watchId,array $input): array
    {
        $watch=$this->watch($userId,$watchId);if(!$watch)throw new RuntimeException('Watch not found.');
        $weather=$this->boolInput($input,'watch_weather',false);$flights=$watch['target_type']==='trip'?$this->boolInput($input,'watch_flights',false):false;$events=$this->boolInput($input,'watch_events',false);$places=$this->boolInput($input,'watch_places',false);
        if(!$weather&&!$flights&&!$events&&!$places)throw new InvalidArgumentException('Keep at least one watch signal enabled.');
        $active=$this->boolInput($input,'is_active',false);$interval=$this->interval($input['interval_minutes']??$watch['interval_minutes']);
        if($active&&empty($watch['is_active']))$this->assertCapacity($userId,(string)$watch['target_key']);
        $next=$active?(new DateTimeImmutable())->format('Y-m-d H:i:s'):null;
        $stmt=$this->pdo->prepare('UPDATE travel_watches SET watch_weather=?,watch_flights=?,watch_events=?,watch_places=?,interval_minutes=?,is_active=?,next_check_at=?,last_status=? WHERE id=? AND user_id=?');
        $stmt->execute([$weather?1:0,$flights?1:0,$events?1:0,$places?1:0,$interval,$active?1:0,$next,$active?'waiting':'paused',$watchId,$userId]);
        return $this->watch($userId,$watchId)??[];
    }

    public function deleteWatch(int $userId,int $watchId): void
    {
        if(!$this->ready())return;$this->pdo->prepare('DELETE FROM travel_watches WHERE id=? AND user_id=?')->execute([$watchId,$userId]);
    }

    public function runDue(int $limit=25): array
    {
        $this->requireReady();$limit=max(1,min(100,$limit));
        $stmt=$this->pdo->query("SELECT * FROM travel_watches WHERE is_active=1 AND (next_check_at IS NULL OR next_check_at<=NOW()) ORDER BY COALESCE(next_check_at,'1970-01-01') ASC,id ASC LIMIT $limit");
        $rows=$stmt->fetchAll()?:[];$result=['checked'=>0,'alerts'=>0,'errors'=>0,'watches'=>[]];
        foreach($rows as $watch){
            if(!$this->claimWatch((int)$watch['id'],(int)$watch['interval_minutes']))continue;
            try{$run=$this->runOne($watch);$result['checked']++;$result['alerts']+=(int)$run['alerts'];$result['watches'][]=$run;}
            catch(Throwable $e){$result['checked']++;$result['errors']++;$this->markFailure((int)$watch['id'],(int)$watch['interval_minutes'],$e->getMessage());$result['watches'][]=['id'=>(int)$watch['id'],'ok'=>false,'error'=>$this->clip($e->getMessage(),500)];}
        }
        return $result;
    }

    public function runOne(array $watch): array
    {
        $watchId=(int)($watch['id']??0);$userId=(int)($watch['user_id']??0);if($watchId<1||$userId<1)throw new InvalidArgumentException('Invalid watch.');
        $types=$this->enabledTypes($watch);if(!$types)throw new RuntimeException('Watch has no enabled signals.');$results=[];
        if(($watch['target_type']??'')==='trip'){
            $tripId=(int)($watch['dream_trip_id']??0);if($tripId<1)throw new RuntimeException('Watched trip is missing.');
            $results=(new TripIntelligenceService($this->pdo))->refresh($userId,$tripId,$types,true);
            foreach($types as $type){$snapshot=$results[$type]??[];$payload=is_array($snapshot['payload']??null)?$snapshot['payload']:[];$this->storeSnapshot($watchId,$type,$payload,(string)($snapshot['provider']??''));}
        }else{
            $trip=['destination_name'=>(string)$watch['destination_name'],'destination_latitude'=>$watch['destination_latitude'],'destination_longitude'=>$watch['destination_longitude'],'start_date'=>date('Y-m-d'),'end_date'=>(new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d'),'currency'=>'USD'];
            $providers=new TravelDataProviderService($this->pdo);
            foreach($types as $type){$payload=match($type){'weather'=>$providers->weather($trip),'events'=>$providers->events($trip),'places'=>$providers->places($trip),default=>[]};$this->storeSnapshot($watchId,$type,$payload,(string)($payload['provider']??''));$results[$type]=$payload;}
        }
        $alerts=0;$eventRows=[];
        foreach($types as $type){$change=$this->meaningfulChange($watchId,$type);if(!$change)continue;$event=$this->emitAlert($watch,$type,$change);if($event){$alerts++;$eventRows[]=$event;}}
        $interval=$this->interval($watch['interval_minutes']??360);$next=(new DateTimeImmutable())->modify('+'.$interval.' minutes')->format('Y-m-d H:i:s');$status=$this->resultStatus($results);
        $this->pdo->prepare('UPDATE travel_watches SET last_checked_at=NOW(),next_check_at=?,last_change_at=IF(?>0,NOW(),last_change_at),last_status=?,last_error=NULL WHERE id=?')->execute([$next,$alerts,$status,$watchId]);
        return ['id'=>$watchId,'ok'=>true,'types'=>$types,'alerts'=>$alerts,'events'=>$eventRows,'next_check_at'=>$next,'status'=>$status];
    }

    private function upsert(array $row): array
    {
        if(!empty($row['is_active']))$this->assertCapacity((int)$row['user_id'],(string)$row['target_key']);
        $next=!empty($row['is_active'])?(new DateTimeImmutable())->format('Y-m-d H:i:s'):null;
        $sql='INSERT INTO travel_watches (user_id,target_key,target_type,dream_trip_id,destination_catalog_id,destination_name,destination_latitude,destination_longitude,watch_weather,watch_flights,watch_events,watch_places,interval_minutes,is_active,next_check_at,last_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target_type=VALUES(target_type),dream_trip_id=VALUES(dream_trip_id),destination_catalog_id=VALUES(destination_catalog_id),destination_name=VALUES(destination_name),destination_latitude=VALUES(destination_latitude),destination_longitude=VALUES(destination_longitude),watch_weather=VALUES(watch_weather),watch_flights=VALUES(watch_flights),watch_events=VALUES(watch_events),watch_places=VALUES(watch_places),interval_minutes=VALUES(interval_minutes),is_active=VALUES(is_active),next_check_at=IF(VALUES(is_active)=1,NOW(),NULL),last_status=IF(VALUES(is_active)=1,\'waiting\',\'paused\'),last_error=NULL';
        $this->pdo->prepare($sql)->execute([$row['user_id'],$row['target_key'],$row['target_type'],$row['dream_trip_id'],$row['destination_catalog_id'],$row['destination_name'],$row['destination_latitude'],$row['destination_longitude'],$row['watch_weather']?1:0,$row['watch_flights']?1:0,$row['watch_events']?1:0,$row['watch_places']?1:0,$row['interval_minutes'],$row['is_active']?1:0,$next,$row['is_active']?'waiting':'paused']);
        $stmt=$this->pdo->prepare('SELECT * FROM travel_watches WHERE user_id=? AND target_key=? LIMIT 1');$stmt->execute([$row['user_id'],$row['target_key']]);return $stmt->fetch()?:[];
    }

    private function storeSnapshot(int $watchId,string $type,array $payload,string $provider=''): void
    {
        $provider=trim((string)($payload['provider']??$provider))?:'Vacation Brain';$ok=!empty($payload['ok']);$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)$json='{}';$fingerprint=hash('sha256',$type.'|'.$provider.'|'.$json);
        $stmt=$this->pdo->prepare('INSERT INTO travel_watch_snapshots (watch_id,data_type,provider,source_status,payload_json,fingerprint,observed_at) VALUES (?,?,?,?,?,?,NOW())');$stmt->execute([$watchId,$type,$provider,$ok?'success':'failed',$json,$fingerprint]);
    }

    private function meaningfulChange(int $watchId,string $type): ?array
    {
        $stmt=$this->pdo->prepare('SELECT payload_json,provider,source_status,observed_at FROM travel_watch_snapshots WHERE watch_id=? AND data_type=? AND source_status=\'success\' ORDER BY observed_at DESC,id DESC LIMIT 2');$stmt->execute([$watchId,$type]);$rows=$stmt->fetchAll()?:[];if(count($rows)<2)return null;
        $now=json_decode((string)$rows[0]['payload_json'],true)?:[];$before=json_decode((string)$rows[1]['payload_json'],true)?:[];
        if($type==='flights'){
            $a=$now['min_price']??null;$b=$before['min_price']??null;
            if(is_numeric($a)&&is_numeric($b)&&$b>0){$delta=(float)$a-(float)$b;$abs=abs($delta);if($delta<0&&$abs>=max(25.0,(float)$b*.05))return ['event_type'=>'fare_drop','direction'=>'down','title'=>'Flight price moved in your favor','body'=>'Lowest indicative fare dropped $'.number_format($abs,0).' to $'.number_format((float)$a,0).'.','key'=>'fare:'.round((float)$a,0),'target_tab'=>'flights'];if($delta>0&&$abs>=max(40.0,(float)$b*.10))return ['event_type'=>'fare_rise','direction'=>'up','title'=>'Flight price moved up','body'=>'Lowest indicative fare rose $'.number_format($abs,0).' to $'.number_format((float)$a,0).'.','key'=>'fare:'.round((float)$a,0),'target_tab'=>'flights'];}
            $directNow=!empty($now['direct_available']);$directBefore=!empty($before['direct_available']);if($directNow&&!$directBefore)return ['event_type'=>'direct_flight','direction'=>'up','title'=>'A direct-flight quote appeared','body'=>'The latest indicative flight snapshot now includes a direct option.','key'=>'direct:appeared','target_tab'=>'flights'];return null;
        }
        if($type==='weather'){
            $alertNow=$now['alerts'][0]??null;$alertBefore=$before['alerts'][0]??null;$alertKey=$this->alertKey($alertNow);if($alertKey!==''&&$alertKey!==$this->alertKey($alertBefore))return ['event_type'=>'weather_alert','direction'=>'risk_up','title'=>'Weather alert for your destination','body'=>$this->clip((string)($alertNow['headline']??$alertNow['event']??'A new weather alert is active.'),500),'key'=>'alert:'.$alertKey,'target_tab'=>'weather'];
            $a=$now['days'][0]??[];$b=$before['days'][0]??[];$rainA=$a['precip_probability']??null;$rainB=$b['precip_probability']??null;if(is_numeric($rainA)&&is_numeric($rainB)&&abs((float)$rainA-(float)$rainB)>=20){$up=(float)$rainA>(float)$rainB;return ['event_type'=>'weather_shift','direction'=>$up?'risk_up':'risk_down','title'=>$up?'Rain risk increased':'Rain risk improved','body'=>'Near-term rain probability shifted from '.round((float)$rainB).'% to '.round((float)$rainA).'%.','key'=>'rain:'.round((float)$rainA),'target_tab'=>'weather'];}
            $highA=$a['high']??null;$highB=$b['high']??null;if(is_numeric($highA)&&is_numeric($highB)&&abs((float)$highA-(float)$highB)>=7){$warmer=(float)$highA>(float)$highB;return ['event_type'=>'weather_shift','direction'=>$warmer?'warmer':'cooler','title'=>'Forecast temperature shifted','body'=>'Near-term high moved from '.round((float)$highB).'° to '.round((float)$highA).'°.','key'=>'temp:'.round((float)$highA),'target_tab'=>'weather'];}return null;
        }
        if($type==='events'){
            $nowItems=is_array($now['items']??null)?$now['items']:[];$beforeIds=$this->ids($before['items']??[]);$new=[];foreach($nowItems as $item){if(!is_array($item))continue;$id=(string)($item['id']??'');if($id!==''&&!in_array($id,$beforeIds,true))$new[]=$item;}if(!$new)return null;$first=$new[0];$body=count($new).' new matching event'.(count($new)===1?'':'s').' appeared'.(!empty($first['name'])?': '.(string)$first['name']:'').(!empty($first['date'])?' on '.(string)$first['date']:'').'.';return ['event_type'=>'new_events','direction'=>'up','title'=>'New event opportunity','body'=>$this->clip($body,500),'key'=>'events:'.implode(',',array_slice(array_map(static fn($i)=>(string)($i['id']??''),$new),0,5)),'target_tab'=>'events'];
        }
        if($type==='places'){
            $nowItems=array_slice((array)($now['items']??[]),0,10);$beforeItems=array_slice((array)($before['items']??[]),0,10);$beforeIds=$this->ids($beforeItems);$new=[];foreach($nowItems as $item){if(!is_array($item))continue;$id=(string)($item['id']??'');if($id!==''&&!in_array($id,$beforeIds,true))$new[]=$item;}
            if(count($new)>=2){$first=$new[0];return ['event_type'=>'local_change','direction'=>'up','title'=>'Fresh local options surfaced','body'=>count($new).' new places entered the top local results'.(!empty($first['name'])?', including '.(string)$first['name']:'').'.','key'=>'places:'.implode(',',array_slice(array_map(static fn($i)=>(string)($i['id']??''),$new),0,5)),'target_tab'=>'local'];}
            $top=$nowItems[0]??null;$old=$beforeItems[0]??null;if(is_array($top)&&is_array($old)&&(string)($top['id']??'')!==(string)($old['id']??'')&&(float)($top['rating']??0)>=4.5)return ['event_type'=>'local_change','direction'=>'up','title'=>'A new local favorite moved to the top','body'=>(string)($top['name']??'A local place').' is now a top result'.(isset($top['rating'])?' at '.number_format((float)$top['rating'],1).'★':'').'.','key'=>'top:'.(string)($top['id']??''),'target_tab'=>'local'];return null;
        }
        return null;
    }

    private function emitAlert(array $watch,string $type,array $change): ?array
    {
        $watchId=(int)$watch['id'];$userId=(int)$watch['user_id'];$fingerprint=hash('sha256',$type.'|'.(string)$change['event_type'].'|'.(string)$change['key'].'|'.date('Y-m-d'));
        $metadata=['target_key'=>$watch['target_key'],'target_type'=>$watch['target_type'],'dream_trip_id'=>$watch['dream_trip_id'],'destination_catalog_id'=>$watch['destination_catalog_id'],'target_tab'=>$change['target_tab']??'overview','change_key'=>$change['key']??''];
        $stmt=$this->pdo->prepare('INSERT IGNORE INTO travel_watch_events (watch_id,user_id,data_type,event_type,direction,title,body,fingerprint,metadata_json,notified_at) VALUES (?,?,?,?,?,?,?,?,?,NULL)');$stmt->execute([$watchId,$userId,$type,$change['event_type'],$change['direction']??'changed',$this->clip((string)$change['title'],180),$this->clip((string)$change['body'],600),$fingerprint,json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);if($stmt->rowCount()<1)return null;
        $eventId=(int)$this->pdo->lastInsertId();$url=$this->actionUrl($watch,(string)($change['target_tab']??'overview'));
        (new NotificationService($this->pdo))->create($userId,'travel_watch',(string)$change['title'],(string)$change['body'],$url,null,null,'travel-watch:'.$fingerprint);
        $this->pdo->prepare('UPDATE travel_watch_events SET notified_at=NOW() WHERE id=?')->execute([$eventId]);
        if(($watch['target_type']??'')==='trip'&&db_table_exists('trip_agent_messages')){$tripId=(int)($watch['dream_trip_id']??0);if($tripId>0){$agentFingerprint=hash('sha256','watch-alert|'.$fingerprint);$body='Watch alert: '.(string)$change['title']."\n".(string)$change['body'];$meta=['kind'=>'watch_alert','watch_id'=>$watchId,'data_type'=>$type,'target_tab'=>$change['target_tab']??'overview','event_id'=>$eventId];$q=$this->pdo->prepare('INSERT IGNORE INTO trip_agent_messages (dream_trip_id,user_id,agent_type,role,body,fingerprint,metadata_json) VALUES (?,?,\'overview\',\'assistant\',?,?,?)');$q->execute([$tripId,$userId,$body,$agentFingerprint,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}}
        return ['id'=>$eventId,'title'=>$change['title'],'body'=>$change['body'],'url'=>$url,'data_type'=>$type];
    }

    private function actionUrl(array $watch,string $tab): string
    {
        if(($watch['target_type']??'')==='trip'&&(int)($watch['dream_trip_id']??0)>0)return app_url('dream-trip.php?id='.(int)$watch['dream_trip_id'].'&tab='.rawurlencode($tab));
        if((int)($watch['destination_catalog_id']??0)>0)return app_url('destination-report.php?destination_id='.(int)$watch['destination_catalog_id']);
        return app_url('destination-report.php?q='.rawurlencode((string)$watch['destination_name']));
    }

    private function enabledTypes(array $watch): array
    {
        $out=[];if(!empty($watch['watch_weather']))$out[]='weather';if(!empty($watch['watch_flights'])&&($watch['target_type']??'')==='trip')$out[]='flights';if(!empty($watch['watch_events']))$out[]='events';if(!empty($watch['watch_places']))$out[]='places';return $out;
    }

    private function resultStatus(array $results): string
    {
        $ok=0;$failed=0;foreach($results as $row){$payload=is_array($row['payload']??null)?$row['payload']:$row;if(!empty($payload['ok']))$ok++;else$failed++;}if($ok>0&&$failed===0)return 'healthy';if($ok>0)return 'partial';return 'error';
    }

    private function markFailure(int $watchId,int $minutes,string $error): void
    {
        $minutes=$this->interval($minutes);$next=(new DateTimeImmutable())->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s');$this->pdo->prepare('UPDATE travel_watches SET last_checked_at=NOW(),next_check_at=?,last_status=\'error\',last_error=? WHERE id=?')->execute([$next,$this->clip($error,500),$watchId]);
    }

    private function claimWatch(int $watchId,int $minutes): bool
    {
        $minutes=$this->interval($minutes);$next=(new DateTimeImmutable())->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s');$stmt=$this->pdo->prepare("UPDATE travel_watches SET next_check_at=?,last_status='checking' WHERE id=? AND is_active=1 AND (next_check_at IS NULL OR next_check_at<=NOW())");$stmt->execute([$next,$watchId]);return $stmt->rowCount()===1;
    }

    private function assertCapacity(int $userId,string $targetKey): void
    {
        $stmt=$this->pdo->prepare('SELECT is_active FROM travel_watches WHERE user_id=? AND target_key=? LIMIT 1');$stmt->execute([$userId,$targetKey]);$existing=$stmt->fetchColumn();if($existing!==false&&(int)$existing===1)return;
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM travel_watches WHERE user_id=? AND is_active=1');$count->execute([$userId]);if((int)$count->fetchColumn()>=self::MAX_ACTIVE_WATCHES)throw new RuntimeException('You can actively watch up to '.self::MAX_ACTIVE_WATCHES.' destinations or trips at once. Pause one before adding another.');
    }

    private function resolveDestinationTarget(int $catalogId,string $name,mixed $lat,mixed $lng): array
    {
        $catalogId=max(0,$catalogId);$name=trim($name);$row=null;
        if(db_table_exists('destination_catalog')){
            if($catalogId>0){$stmt=$this->pdo->prepare('SELECT id,name,latitude,longitude FROM destination_catalog WHERE id=? LIMIT 1');$stmt->execute([$catalogId]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('Destination not found.');}
            elseif($name!==''){$stmt=$this->pdo->prepare("SELECT id,name,latitude,longitude FROM destination_catalog WHERE LOWER(name)=LOWER(?) AND status='active' ORDER BY id ASC LIMIT 1");$stmt->execute([$name]);$row=$stmt->fetch()?:null;}
        }
        if($row){$catalogId=(int)$row['id'];$name=trim((string)$row['name'])?:$name;$lat=$row['latitude']??$lat;$lng=$row['longitude']??$lng;}
        return [$catalogId,$name,$lat,$lng];
    }

    private function watch(int $userId,int $watchId): ?array
    {
        $this->requireReady();$stmt=$this->pdo->prepare('SELECT * FROM travel_watches WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$watchId,$userId]);return $stmt->fetch()?:null;
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before using Destination Watches.');
    }

    private function interval(mixed $value): int
    {
        $value=(int)$value;return in_array($value,self::INTERVALS,true)?$value:360;
    }

    private function boolInput(array $input,string $key,bool $default): bool
    {
        if(!array_key_exists($key,$input))return $default;$value=$input[$key];if(is_bool($value))return $value;return in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value!==null&&$value!==''&&is_numeric($value)?(float)$value:null;
    }

    private function ids(array $items): array
    {
        $out=[];foreach($items as $item){if(!is_array($item))continue;$id=trim((string)($item['id']??''));if($id!=='')$out[]=$id;}return array_values(array_unique($out));
    }

    private function alertKey(mixed $alert): string
    {
        if(!is_array($alert))return '';return trim((string)($alert['event']??'').'|'.(string)($alert['headline']??''));
    }

    private function clip(string $value,int $max): string
    {
        $value=trim($value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
