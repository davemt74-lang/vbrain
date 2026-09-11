<?php
declare(strict_types=1);

final class TripTravelOperationsService
{
    private const STATES=['planning','booking','ready','traveling','completed'];
    private const OPS_STATUSES=['unknown','scheduled','on_time','delayed','cancelled','completed'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('dream_trips')
            && db_column_exists('dream_trips','operational_state')
            && db_table_exists('trip_bookings')
            && db_column_exists('trip_bookings','operational_status')
            && db_table_exists('trip_operation_events');
    }

    public function snapshot(int $userId,int $tripId,?string $date=null,bool $sync=true): array
    {
        if($userId<1||$tripId<1)throw new InvalidArgumentException('Trip is required.');
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);
        if(!$trip)throw new OutOfBoundsException('Trip not found.');
        if(!$this->ready())return ['ready'=>false,'trip'=>$trip,'state'=>'planning','selected_date'=>$this->dateOrToday($date),'timeline'=>[],'timeline_groups'=>[],'wallet'=>[],'flights'=>[],'lodging'=>[],'alerts'=>[],'readiness'=>[],'countdown'=>[],'agents'=>[],'offline'=>[]];

        $bookingService=new TripBookingService($this->pdo);$booking=$bookingService->snapshot($userId,$tripId);$readiness=is_array($booking['readiness']??null)?$booking['readiness']:[];$bookings=is_array($booking['bookings']??null)?$booking['bookings']:[];$requirements=is_array($booking['requirements']??null)?$booking['requirements']:[];
        $state=$this->deriveState($trip,$readiness);
        if($sync)$state=$this->syncState($userId,$tripId,$trip,$state,'automatic');
        $selected=$this->selectedDate($date,$trip);
        $timeline=$this->buildTimeline($trip,$bookings,$selected);
        $alerts=$this->alerts($userId,$tripId,$trip,$bookings,$requirements,$selected);
        if($sync){$this->syncDisruptionActions($userId,$tripId,$alerts);$this->pdo->prepare('UPDATE dream_trips SET last_operations_checked_at=NOW() WHERE id=? AND user_id=?')->execute([$tripId,$userId]);}
        $snapshot=[
            'ready'=>true,'generated_at'=>date(DATE_ATOM),'trip'=>$this->publicTrip($trip,$state),'state'=>$state,'state_label'=>$this->stateLabel($state),'selected_date'=>$selected,
            'countdown'=>$this->countdown($trip,$readiness,$state),'readiness'=>$readiness,'timeline'=>$timeline,'timeline_groups'=>$this->timelineGroups($timeline,$selected),
            'wallet'=>$this->wallet($bookings),'flights'=>$this->flightOperations($bookings),'lodging'=>$this->lodgingOperations($bookings),'alerts'=>$alerts,'agents'=>$this->agentState($userId,$tripId),
            'watch_signals'=>$this->watchSignals($userId,$tripId),'navigation'=>$this->navigation($trip,$selected),
        ];
        $snapshot['offline']=$this->offlineSnapshot($snapshot);
        return $snapshot;
    }

    public function setState(int $userId,int $tripId,string $state,string $reason='manual'): string
    {
        $this->requireReady();$state=strtolower(trim($state));if(!in_array($state,self::STATES,true))throw new InvalidArgumentException('Unknown travel state.');
        $trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new OutOfBoundsException('Trip not found.');
        return $this->syncState($userId,$tripId,$trip,$state,$reason);
    }

    public function updateBookingOperations(int $userId,int $tripId,int $bookingId,array $input): array
    {
        $this->requireReady();$booking=$this->ownedBooking($userId,$tripId,$bookingId);if(!$booking)throw new OutOfBoundsException('Booking not found.');
        $status=strtolower(trim((string)($input['operational_status']??$booking['operational_status']??'unknown')));if(!in_array($status,self::OPS_STATUSES,true))throw new InvalidArgumentException('Unknown operational status.');
        $terminal=$this->nullableClip((string)($input['terminal']??$booking['terminal']??''),80);$gate=$this->nullableClip((string)($input['gate']??$booking['gate']??''),40);$location=$this->nullableClip((string)($input['location_name']??$booking['location_name']??''),180);$address=$this->nullableClip((string)($input['location_address']??$booking['location_address']??''),300);$note=$this->nullableClip((string)($input['operational_note']??$booking['operational_note']??''),500);$checkin=$this->nullableClip((string)($input['checkin_url']??$booking['checkin_url']??''),1500);
        if($checkin!==null&&!preg_match('#^https?://#i',$checkin))throw new InvalidArgumentException('Check-in URL must begin with http:// or https://.');
        $changed=$status!==(string)($booking['operational_status']??'unknown');
        $stmt=$this->pdo->prepare('UPDATE trip_bookings SET terminal=?,gate=?,location_name=?,location_address=?,operational_status=?,operational_note=?,status_source=\'manual\',last_status_at=IF(?=1,NOW(),last_status_at),checkin_url=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?');
        $stmt->execute([$terminal,$gate,$location,$address,$status,$note,$changed?1:0,$checkin,$bookingId,$userId,$tripId]);
        $updated=$this->ownedBooking($userId,$tripId,$bookingId);if(!$updated)throw new RuntimeException('Booking operation update failed.');
        if($changed)$this->recordEvent($userId,$tripId,$bookingId,'booking_status','low','Travel status updated',(string)$updated['title'].' is now '.str_replace('_',' ',$status).'.','booking-status:'.$bookingId.':'.$status,['status'=>$status,'source'=>'manual']);
        return $updated;
    }

    public function syncUpcoming(int $limit=25): array
    {
        if(!$this->ready())return ['checked'=>0,'updated'=>0,'errors'=>0];$limit=max(1,min(100,$limit));
        $stmt=$this->pdo->query("SELECT id,user_id FROM dream_trips WHERE status<>'abandoned' AND operational_state<>'completed' AND (end_date IS NULL OR end_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) AND (start_date IS NULL OR start_date<=DATE_ADD(CURDATE(),INTERVAL 14 DAY) OR operational_state IN ('ready','traveling')) ORDER BY COALESCE(start_date,'9999-12-31') ASC,id ASC LIMIT $limit");
        $result=['checked'=>0,'updated'=>0,'errors'=>0];foreach($stmt->fetchAll()?:[] as $row){$result['checked']++;try{$before=$this->tripState((int)$row['user_id'],(int)$row['id']);$snap=$this->snapshot((int)$row['user_id'],(int)$row['id'],null,true);if(($snap['state']??'')!==$before)$result['updated']++;}catch(Throwable $e){$result['errors']++;error_log('Trip operations sync failed: '.$e->getMessage());}}return $result;
    }

    public function offlineSnapshot(array $snapshot): array
    {
        $timeline=[];foreach(is_array($snapshot['timeline']??null)?$snapshot['timeline']:[] as $item){$timeline[]=['key'=>$item['key']??'','type'=>$item['type']??'other','title'=>$item['title']??'','time_label'=>$item['time_label']??'','location'=>$item['location']??'','address'=>$item['address']??'','phase'=>$item['phase']??'later','terminal'=>$item['terminal']??'','gate'=>$item['gate']??'','operational_status'=>$item['operational_status']??'unknown'];}
        $wallet=[];foreach(is_array($snapshot['wallet']??null)?$snapshot['wallet']:[] as $item){$wallet[]=['type'=>$item['type']??'other','title'=>$item['title']??'','provider'=>$item['provider']??'','status'=>$item['status']??'','starts_at'=>$item['starts_at']??null,'location'=>$item['location']??'','address'=>$item['address']??'','terminal'=>$item['terminal']??'','gate'=>$item['gate']??''];}
        return ['version'=>1,'saved_at'=>date(DATE_ATOM),'trip_id'=>(int)($snapshot['trip']['id']??0),'trip_name'=>(string)($snapshot['trip']['name']??''),'destination'=>(string)($snapshot['trip']['destination_name']??''),'state'=>(string)($snapshot['state']??'planning'),'selected_date'=>(string)($snapshot['selected_date']??date('Y-m-d')),'countdown'=>$snapshot['countdown']??[],'timeline'=>$timeline,'wallet'=>$wallet,'alerts'=>array_map(fn($a)=>['title'=>$a['title']??'','body'=>$a['body']??'','severity'=>$a['severity']??'low'],array_slice(is_array($snapshot['alerts']??null)?$snapshot['alerts']:[],0,8)),'privacy_note'=>'Offline copy excludes confirmation codes, provider URLs, booking notes, payment details, and other sensitive reservation fields.'];
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;$trips=is_array($snapshot['upcoming_trips']??null)?$snapshot['upcoming_trips']:[];
        foreach($trips as &$trip){$tripId=(int)($trip['id']??0);if($tripId<1)continue;try{$state=$this->tripState($userId,$tripId);$trip['operational_state']=$state;$trip['operational_state_label']=$this->stateLabel($state);$trip['travel_mode_url']=app_url('travel-mode.php?id='.$tripId);$trip['travel_mode_prominent']=in_array($state,['ready','traveling'],true)||(($trip['days_until']??999)!==null&&(int)$trip['days_until']<=7);}catch(Throwable $e){}}unset($trip);$snapshot['upcoming_trips']=$trips;return $snapshot;
    }

    private function deriveState(array $trip,array $readiness): string
    {
        $current=(string)($trip['operational_state']??'planning');$today=date('Y-m-d');$start=trim((string)($trip['start_date']??''));$end=trim((string)($trip['end_date']??''));
        if($current==='completed'||($trip['status']??'')==='completed'||($end!==''&&$end<$today))return 'completed';
        if($current==='traveling')return 'traveling';
        if($start!==''&&$start<=$today&&($end===''||$end>=$today))return 'traveling';
        if($current==='ready')return 'ready';
        $days=$start!==''?(int)floor((strtotime($start)-strtotime($today))/86400):null;$pct=(int)($readiness['percent']??0);
        if($days!==null&&$days>=0&&$days<=7&&$pct>=90)return 'ready';
        if($pct>0||in_array((string)($trip['status']??''),['planning','booked'],true))return 'booking';
        return 'planning';
    }

    private function syncState(int $userId,int $tripId,array $trip,string $state,string $reason): string
    {
        $current=(string)($trip['operational_state']??'planning');if($current===$state)return $state;
        $sql='UPDATE dream_trips SET operational_state=?,travel_mode_started_at=IF(?=\'traveling\',COALESCE(travel_mode_started_at,NOW()),travel_mode_started_at),travel_mode_completed_at=IF(?=\'completed\',COALESCE(travel_mode_completed_at,NOW()),travel_mode_completed_at),updated_at=NOW() WHERE id=? AND user_id=?';$this->pdo->prepare($sql)->execute([$state,$state,$state,$tripId,$userId]);
        $this->recordEvent($userId,$tripId,null,'state_changed','low','Trip moved to '.$this->stateLabel($state),'Vacation Brain changed the operational lifecycle from '.$this->stateLabel($current).' to '.$this->stateLabel($state).'.','state:'.$current.':'.$state.':'.date('Y-m-d'),['from'=>$current,'to'=>$state,'reason'=>$reason]);return $state;
    }

    private function buildTimeline(array $trip,array $bookings,string $selected): array
    {
        $items=[];$now=time();$today=date('Y-m-d');
        foreach($bookings as $b){$status=(string)($b['status']??'');if(!in_array($status,['booked','confirmed','changed'],true))continue;$starts=(string)($b['starts_at']??'');$ends=(string)($b['ends_at']??'');$type=(string)($b['booking_type']??'other');
            $include=$starts!==''&&substr($starts,0,10)===$selected;if($type==='lodging'&&$starts!==''&&$ends!==''&&substr($starts,0,10)<=$selected&&substr($ends,0,10)>=$selected)$include=true;if(!$include)continue;
            $ts=$starts!==''?strtotime($starts):false;if($type==='lodging'&&substr($starts,0,10)<$selected)$ts=strtotime($selected.' 08:00:00');$endTs=$ends!==''?strtotime($ends):false;$phase='later';if($selected===$today){if($ts&&$endTs&&$ts<=$now&&$endTs>=$now)$phase='now';elseif($ts&&$ts>$now)$phase='upcoming';elseif($endTs&&$endTs<$now)$phase='earlier';}
            $items[]=['key'=>'booking:'.(int)$b['id'],'source'=>'booking','booking_id'=>(int)$b['id'],'type'=>$type,'title'=>(string)$b['title'],'timestamp'=>$ts?:strtotime($selected.' 12:00:00'),'time_label'=>$this->timeLabel($starts,$type==='lodging'&&substr($starts,0,10)<$selected?'Stay':'Booked'),'phase'=>$phase,'location'=>(string)($b['location_name']??''),'address'=>(string)($b['location_address']??''),'terminal'=>(string)($b['terminal']??''),'gate'=>(string)($b['gate']??''),'operational_status'=>(string)($b['operational_status']??'unknown'),'status_source'=>(string)($b['status_source']??'manual'),'provider'=>(string)($b['provider_name']??''),'confirmation_code'=>(string)($b['confirmation_code']??''),'checkin_url'=>(string)($b['checkin_url']??''),'url'=>app_url('trip-bookings.php?id='.(int)$trip['id'])];
        }
        foreach(is_array($trip['items']??null)?$trip['items']:[] as $i){if((string)($i['scheduled_date']??'')!==$selected)continue;$daypart=(string)($i['daypart']??'anytime');$time=match($daypart){'morning'=>'09:00:00','afternoon'=>'14:00:00','evening'=>'19:00:00',default=>'12:00:00'};$ts=strtotime($selected.' '.$time);$phase=$selected===$today?($ts>$now?'upcoming':'earlier'):'later';$items[]=['key'=>'item:'.(int)$i['id'],'source'=>'itinerary','item_id'=>(int)$i['id'],'type'=>(string)$i['item_type'],'title'=>(string)$i['title'],'timestamp'=>$ts,'time_label'=>ucfirst($daypart?:'Anytime'),'phase'=>$phase,'location'=>'','address'=>'','terminal'=>'','gate'=>'','operational_status'=>'unknown','status_source'=>'','provider'=>(string)($i['source_provider']??''),'confirmation_code'=>'','checkin_url'=>'','url'=>app_url('dream-trip.php?id='.(int)$trip['id'].'&tab=itinerary')];}
        usort($items,fn($a,$b)=>(int)$a['timestamp']<=>(int)$b['timestamp']);if($selected===$today){$marked=false;foreach($items as &$item){if($item['phase']==='upcoming'&&!$marked){$item['phase']='next';$marked=true;}elseif($item['phase']==='upcoming')$item['phase']='later';}unset($item);}return $items;
    }

    private function timelineGroups(array $timeline,string $selected): array
    {
        $groups=['now'=>[],'next'=>[],'later'=>[],'earlier'=>[]];foreach($timeline as $item){$phase=(string)($item['phase']??'later');if(!isset($groups[$phase]))$phase='later';$groups[$phase][]=$item;}return $groups;
    }

    private function alerts(int $userId,int $tripId,array $trip,array $bookings,array $requirements,string $selected): array
    {
        $out=[];$now=time();$day=$now+86400;$three=$now+3*86400;
        foreach($bookings as $b){$bookingId=(int)$b['id'];$status=(string)$b['status'];$ops=(string)($b['operational_status']??'unknown');$type=(string)$b['booking_type'];
            if($status==='cancelled'||$ops==='cancelled')$out[]=$this->alert('booking-cancelled:'.$bookingId,'high','Reservation cancelled',(string)$b['title'].' needs a recovery plan.',$bookingId,$type,98);
            elseif($status==='changed')$out[]=$this->alert('booking-changed:'.$bookingId,'high','Reservation changed',(string)$b['title'].' changed. Reconfirm the live details and adjust the trip if needed.',$bookingId,$type,95);
            elseif($ops==='delayed')$out[]=$this->alert('booking-delayed:'.$bookingId,'high','Travel delay recorded',(string)$b['title'].' is marked delayed. Review downstream timing and alternatives.',$bookingId,$type,94);
            $check=$this->ts($b['checkin_opens_at']??null);if($check&&$check>=$now&&$check<=$day&&in_array($status,['booked','confirmed'],true))$out[]=$this->alert('checkin:'.$bookingId,'medium','Check-in is opening soon',(string)$b['title'].' can be checked in within 24 hours.',$bookingId,$type,80);
            $cancel=$this->ts($b['cancellation_deadline']??null);if($cancel&&$cancel>=$now&&$cancel<=$day&&$status!=='cancelled')$out[]=$this->alert('cancel-deadline:'.$bookingId,'medium','Cancellation deadline is close',(string)$b['title'].' reaches its cancellation deadline within 24 hours.',$bookingId,$type,82);
        }
        foreach($requirements as $r){if((int)($r['is_required']??0)!==1||(string)($r['status']??'')!=='open')continue;$due=$this->ts($r['due_at']??null);if(!$due)continue;if($due<$now)$out[]=$this->alert('requirement-overdue:'.(int)$r['id'],'high','Required trip item is overdue',(string)$r['title'].' must be resolved before travel.',null,(string)$r['requirement_type'],96);elseif($due<=$three)$out[]=$this->alert('requirement-due:'.(int)$r['id'],'medium','Required trip item due soon',(string)$r['title'].' is due within three days.',null,(string)$r['requirement_type'],84);}
        foreach($this->watchSignals($userId,$tripId) as $signal){$severity=in_array((string)($signal['data_type']??''),['weather','flights'],true)?'medium':'low';$out[]=['key'=>'watch:'.(int)$signal['id'],'severity'=>$severity,'title'=>(string)$signal['title'],'body'=>(string)$signal['body'],'booking_id'=>null,'booking_type'=>(string)$signal['data_type'],'priority'=>$severity==='medium'?78:60,'source'=>'watch','target_agent'=>$this->agentForType((string)$signal['data_type'])];}
        usort($out,fn($a,$b)=>(int)$b['priority']<=>(int)$a['priority']);$seen=[];$dedup=[];foreach($out as $a){if(isset($seen[$a['key']]))continue;$seen[$a['key']]=true;$dedup[]=$a;}return array_slice($dedup,0,12);
    }

    private function alert(string $key,string $severity,string $title,string $body,?int $bookingId,string $type,int $priority): array{return ['key'=>$key,'severity'=>$severity,'title'=>$title,'body'=>$body,'booking_id'=>$bookingId,'booking_type'=>$type,'priority'=>$priority,'source'=>'booking','target_agent'=>$this->agentForType($type)];}

    private function syncDisruptionActions(int $userId,int $tripId,array $alerts): void
    {
        if(!db_table_exists('trip_agent_actions'))return;foreach($alerts as $alert){if(($alert['source']??'')!=='booking'||(int)($alert['priority']??0)<90)continue;$key=(string)$alert['key'];$sourceKey='travel-ops:'.substr(hash('sha256',$key.'|'.$alert['title'].'|'.$alert['body']),0,48);$agent=(string)($alert['target_agent']??'itinerary');$meta=['source'=>'travel_day_operations','signal_key'=>$key,'booking_id'=>$alert['booking_id']??null,'requires_user_approval'=>true,'external_booking_not_completed'=>true];$json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'{}';
            $sql="INSERT INTO trip_agent_actions (user_id,dream_trip_id,batch_id,suggestion_key,source_key,agent_type,action_kind,title,body,priority,target_tab,status,metadata_json) VALUES (?,?,NULL,?,?,?,?,?,?,?,?, 'open',?) ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),priority=VALUES(priority),metadata_json=VALUES(metadata_json),status=IF(status IN ('accepted','dismissed','completed'),status,'open'),updated_at=NOW()";
            $this->pdo->prepare($sql)->execute([$userId,$tripId,substr('recovery-'.$key,0,80),$sourceKey,$agent,'recovery',(string)$alert['title'],(string)$alert['body'].' Vacation Brain can propose a recovery plan, but any replacement booking still requires live provider confirmation.',(int)$alert['priority'],$agent,$json]);
            $created=$this->recordEvent($userId,$tripId,$alert['booking_id']??null,'disruption_detected',(string)$alert['severity'],(string)$alert['title'],(string)$alert['body'],$sourceKey,$meta);if($created){try{(new NotificationService($this->pdo))->create($userId,'trip_operations',(string)$alert['title'],(string)$alert['body'],app_url('travel-mode.php?id='.$tripId),null,null,'trip-ops:'.$tripId.':'.substr(hash('sha256',$key),0,32));}catch(Throwable $e){}}
        }
    }

    private function wallet(array $bookings): array
    {
        $out=[];foreach($bookings as $b){if(!in_array((string)$b['status'],['booked','confirmed','changed'],true))continue;$out[]=['id'=>(int)$b['id'],'type'=>(string)$b['booking_type'],'title'=>(string)$b['title'],'provider'=>(string)($b['provider_name']??''),'confirmation_code'=>(string)($b['confirmation_code']??''),'status'=>(string)$b['status'],'payment_status'=>(string)$b['payment_status'],'starts_at'=>$b['starts_at']??null,'ends_at'=>$b['ends_at']??null,'location'=>(string)($b['location_name']??''),'address'=>(string)($b['location_address']??''),'terminal'=>(string)($b['terminal']??''),'gate'=>(string)($b['gate']??''),'provider_url'=>(string)($b['provider_url']??''),'checkin_url'=>(string)($b['checkin_url']??''),'operational_status'=>(string)($b['operational_status']??'unknown'),'status_source'=>(string)($b['status_source']??'manual'),'last_status_at'=>$b['last_status_at']??null];}return $out;
    }

    private function flightOperations(array $bookings): array{return array_values(array_filter($this->wallet($bookings),fn($b)=>$b['type']==='flight'));}
    private function lodgingOperations(array $bookings): array{return array_values(array_filter($this->wallet($bookings),fn($b)=>$b['type']==='lodging'));}

    private function agentState(int $userId,int $tripId): array
    {
        $out=[];if(!db_table_exists('trip_agent_jobs'))return $out;$stmt=$this->pdo->prepare("SELECT agent_type,status,progress,status_text,updated_at FROM trip_agent_jobs WHERE user_id=? AND dream_trip_id=? AND agent_type IN ('weather','flights','local','itinerary','overview') ORDER BY id DESC");$stmt->execute([$userId,$tripId]);$seen=[];foreach($stmt->fetchAll()?:[] as $row){$agent=(string)$row['agent_type'];if(isset($seen[$agent]))continue;$seen[$agent]=true;$out[$agent]=['status'=>(string)$row['status'],'progress'=>(int)$row['progress'],'status_text'=>(string)($row['status_text']??''),'updated_at'=>(string)$row['updated_at']];}return $out;
    }

    private function watchSignals(int $userId,int $tripId): array
    {
        if(!db_table_exists('travel_watch_events')||!db_table_exists('travel_watches'))return [];$stmt=$this->pdo->prepare("SELECT e.id,e.data_type,e.event_type,e.direction,e.title,e.body,e.created_at FROM travel_watch_events e JOIN travel_watches w ON w.id=e.watch_id AND w.user_id=e.user_id WHERE e.user_id=? AND w.dream_trip_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY e.created_at DESC,e.id DESC LIMIT 8");$stmt->execute([$userId,$tripId]);return $stmt->fetchAll()?:[];
    }

    private function countdown(array $trip,array $readiness,string $state): array
    {
        $start=trim((string)($trip['start_date']??''));$days=null;if($start!==''){$ts=strtotime($start);if($ts!==false)$days=(int)floor(($ts-strtotime(date('Y-m-d')))/86400);}if($state==='traveling')$label='Traveling now';elseif($state==='completed')$label='Trip completed';elseif($days===null)$label='Dates flexible';elseif($days<0)$label='Trip date passed';elseif($days===0)$label='Leaving today';elseif($days===1)$label='Leaving tomorrow';else$label='Leaving in '.$days.' days';return ['days_until'=>$days,'label'=>$label,'readiness_percent'=>(int)($readiness['percent']??0),'required_open'=>(int)($readiness['outstanding']??0),'ready_to_book'=>(int)($readiness['ready_to_book']??0),'deadlines_soon'=>(int)($readiness['deadlines_soon']??0)];
    }

    private function navigation(array $trip,string $selected): array
    {
        $prev=(new DateTimeImmutable($selected))->modify('-1 day')->format('Y-m-d');$next=(new DateTimeImmutable($selected))->modify('+1 day')->format('Y-m-d');$start=(string)($trip['start_date']??'');$end=(string)($trip['end_date']??'');return ['previous_date'=>$start!==''&&$prev<$start?null:$prev,'next_date'=>$end!==''&&$next>$end?null:$next,'today_url'=>app_url('travel-mode.php?id='.(int)$trip['id'].'&date='.date('Y-m-d'))];
    }

    private function selectedDate(?string $date,array $trip): string
    {
        $selected=$this->dateOrToday($date);$start=trim((string)($trip['start_date']??''));$end=trim((string)($trip['end_date']??''));if($start!==''&&$selected<$start)$selected=$start;if($end!==''&&$selected>$end)$selected=$end;return $selected;
    }

    private function dateOrToday(?string $date): string{$date=trim((string)$date);if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){[$y,$m,$d]=array_map('intval',explode('-',$date));if(checkdate($m,$d,$y))return $date;}return date('Y-m-d');}
    private function timeLabel(string $value,string $fallback='Anytime'): string{$ts=strtotime($value);return $ts?date('g:i A',$ts):$fallback;}
    private function stateLabel(string $state): string{return match($state){'booking'=>'Booking','ready'=>'Ready','traveling'=>'Traveling','completed'=>'Completed',default=>'Planning'};}
    private function agentForType(string $type): string{return match($type){'flight','flights'=>'flights','event','events'=>'events','restaurant','activity','places','lodging','transport'=>'local','weather'=>'weather',default=>'itinerary'};}

    private function publicTrip(array $trip,string $state): array{return ['id'=>(int)$trip['id'],'name'=>(string)$trip['name'],'destination_name'=>(string)($trip['destination_name']??''),'start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'date_label'=>(string)($trip['date_label']??''),'operational_state'=>$state,'status'=>(string)$trip['status'],'travelers'=>(int)($trip['travelers']??1),'currency'=>(string)($trip['currency']??'USD')];}
    private function tripState(int $userId,int $tripId): string{$stmt=$this->pdo->prepare('SELECT operational_state FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$tripId,$userId]);$state=(string)($stmt->fetchColumn()?:'planning');return in_array($state,self::STATES,true)?$state:'planning';}
    private function ownedBooking(int $userId,int $tripId,int $bookingId): ?array{$stmt=$this->pdo->prepare('SELECT * FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$bookingId,$userId,$tripId]);return $stmt->fetch()?:null;}

    private function recordEvent(int $userId,int $tripId,?int $bookingId,string $type,string $severity,string $title,string $body,string $sourceKey,array $metadata=[]): bool
    {
        $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)?:'{}';$stmt=$this->pdo->prepare('INSERT IGNORE INTO trip_operation_events (user_id,dream_trip_id,booking_id,source_key,event_type,severity,title,body,metadata_json) VALUES (?,?,?,?,?,?,?,?,?)');$stmt->execute([$userId,$tripId,$bookingId,$this->clip($sourceKey,190),$this->clip($type,40),in_array($severity,['low','medium','high'],true)?$severity:'low',$this->clip($title,180),$this->clip($body,700),$json]);return $stmt->rowCount()>0;
    }

    private function ts(mixed $value): ?int{$value=trim((string)$value);if($value==='')return null;$ts=strtotime($value);return $ts===false?null:$ts;}
    private function nullableClip(string $value,int $max): ?string{$value=$this->clip($value,$max);return $value===''?null:$value;}
    private function clip(string $value,int $max): string{$value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Travel Day Operations.');}
}
