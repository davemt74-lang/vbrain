<?php
declare(strict_types=1);

final class TripBookingService
{
    private const BOOKING_TYPES=['flight','lodging','transport','event','restaurant','activity','document','other'];
    private const BOOKING_STATUSES=['unbooked','ready_to_book','booked','confirmed','changed','cancelled'];
    private const PAYMENT_STATUSES=['unknown','unpaid','deposit_paid','paid','refunded'];
    private const REQUIREMENT_STATUSES=['open','complete','not_needed'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_bookings')
            && db_table_exists('trip_readiness_requirements')
            && db_table_exists('trip_booking_events');
    }

    public function snapshot(int $userId,int $tripId): array
    {
        $trip=$this->trip($userId,$tripId);
        if(!$this->ready())return ['ready'=>false,'trip'=>$trip,'readiness'=>$this->emptyReadiness(),'bookings'=>[],'requirements'=>[]];
        $this->ensureDefaults($userId,$tripId,$trip);
        $this->syncHistoricalHandoffs($userId,$tripId);
        $bookings=$this->bookings($userId,$tripId);
        $requirements=$this->requirements($userId,$tripId);
        return ['ready'=>true,'trip'=>$trip,'readiness'=>$this->calculateReadiness($bookings,$requirements),'bookings'=>$bookings,'requirements'=>$requirements];
    }

    public function bookings(int $userId,int $tripId): array
    {
        $this->requireReady();$this->trip($userId,$tripId);
        $stmt=$this->pdo->prepare("SELECT * FROM trip_bookings WHERE user_id=? AND dream_trip_id=? ORDER BY FIELD(status,'changed','ready_to_book','unbooked','booked','confirmed','cancelled'),COALESCE(starts_at,'9999-12-31') ASC,updated_at DESC,id DESC");
        $stmt->execute([$userId,$tripId]);$rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row)$row=$this->decorateBooking($row);unset($row);return $rows;
    }

    public function requirements(int $userId,int $tripId): array
    {
        $this->requireReady();$this->trip($userId,$tripId);
        $stmt=$this->pdo->prepare('SELECT * FROM trip_readiness_requirements WHERE user_id=? AND dream_trip_id=? ORDER BY sort_order ASC,id ASC');$stmt->execute([$userId,$tripId]);return $stmt->fetchAll()?:[];
    }

    public function readiness(int $userId,int $tripId): array
    {
        if(!$this->ready())return $this->emptyReadiness();$trip=$this->trip($userId,$tripId);$this->ensureDefaults($userId,$tripId,$trip);$this->syncHistoricalHandoffs($userId,$tripId);return $this->calculateReadiness($this->bookings($userId,$tripId),$this->requirements($userId,$tripId));
    }

    public function saveBooking(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$trip=$this->trip($userId,$tripId);$id=max(0,(int)($input['booking_id']??0));
        $current=$id>0?$this->booking($userId,$tripId,$id):null;if($id>0&&!$current)throw new OutOfBoundsException('Booking not found.');
        $type=$this->bookingType((string)($input['booking_type']??($current['booking_type']??'other')));$title=$this->clip((string)($input['title']??($current['title']??'')),180);if($title==='')throw new InvalidArgumentException('Booking title is required.');
        $provider=$this->nullableClip((string)($input['provider_name']??($current['provider_name']??'')),180);$confirmation=$this->nullableClip((string)($input['confirmation_code']??($current['confirmation_code']??'')),120);
        $status=$this->bookingStatus((string)($input['status']??($current['status']??'unbooked')));$payment=$this->paymentStatus((string)($input['payment_status']??($current['payment_status']??'unknown')));
        $amount=$this->moneyOrNull($input['amount']??($current['amount']??null));$currency=$this->currency((string)($input['currency']??($current['currency']??($trip['currency']??'USD'))));
        $starts=$this->dateTimeOrNull((string)($input['starts_at']??($current['starts_at']??'')));$ends=$this->dateTimeOrNull((string)($input['ends_at']??($current['ends_at']??'')));if($starts&&$ends&&$ends<$starts)throw new InvalidArgumentException('Booking end time must be after the start time.');
        $cancel=$this->dateTimeOrNull((string)($input['cancellation_deadline']??($current['cancellation_deadline']??'')));$checkin=$this->dateTimeOrNull((string)($input['checkin_opens_at']??($current['checkin_opens_at']??'')));
        $url=$this->nullableClip((string)($input['provider_url']??($current['provider_url']??'')),1500);if($url!==null&&!preg_match('#^https?://#i',$url))throw new InvalidArgumentException('Provider URL must begin with http:// or https://.');
        $notes=$this->nullableClip((string)($input['notes']??($current['notes']??'')),4000);$verified=$status==='confirmed'?date('Y-m-d H:i:s'):($current['last_verified_at']??null);
        if($id>0){
            $stmt=$this->pdo->prepare('UPDATE trip_bookings SET booking_type=?,title=?,provider_name=?,confirmation_code=?,status=?,payment_status=?,amount=?,currency=?,starts_at=?,ends_at=?,cancellation_deadline=?,checkin_opens_at=?,provider_url=?,notes=?,last_verified_at=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?');
            $stmt->execute([$type,$title,$provider,$confirmation,$status,$payment,$amount,$currency,$starts,$ends,$cancel,$checkin,$url,$notes,$verified,$id,$userId,$tripId]);$event='updated';
            if((string)$current['status']!==$status)$this->event($id,$userId,$tripId,'status_changed',['from'=>$current['status'],'to'=>$status]);
        }else{
            $stmt=$this->pdo->prepare("INSERT INTO trip_bookings (user_id,dream_trip_id,booking_type,title,provider_name,confirmation_code,status,payment_status,amount,currency,starts_at,ends_at,cancellation_deadline,checkin_opens_at,provider_url,notes,source,requires_live_confirmation,last_verified_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'manual',0,?)");
            $stmt->execute([$userId,$tripId,$type,$title,$provider,$confirmation,$status,$payment,$amount,$currency,$starts,$ends,$cancel,$checkin,$url,$notes,$verified]);$id=(int)$this->pdo->lastInsertId();$event='created';
        }
        $this->event($id,$userId,$tripId,$event,['booking_type'=>$type,'status'=>$status,'title'=>$title]);return $this->booking($userId,$tripId,$id)??[];
    }

    public function updateBookingStatus(int $userId,int $tripId,int $bookingId,string $status): array
    {
        $this->requireReady();$current=$this->booking($userId,$tripId,$bookingId);if(!$current)throw new OutOfBoundsException('Booking not found.');$status=$this->bookingStatus($status);
        $verified=$status==='confirmed'?date('Y-m-d H:i:s'):($current['last_verified_at']??null);$stmt=$this->pdo->prepare('UPDATE trip_bookings SET status=?,last_verified_at=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?');$stmt->execute([$status,$verified,$bookingId,$userId,$tripId]);if($stmt->rowCount()!==1&&$status!==(string)$current['status'])throw new RuntimeException('Booking status was not updated.');
        if($status!==(string)$current['status'])$this->event($bookingId,$userId,$tripId,'status_changed',['from'=>$current['status'],'to'=>$status]);return $this->booking($userId,$tripId,$bookingId)??[];
    }

    public function saveRequirement(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$this->trip($userId,$tripId);$id=max(0,(int)($input['requirement_id']??0));$current=$id>0?$this->requirement($userId,$tripId,$id):null;if($id>0&&!$current)throw new OutOfBoundsException('Readiness requirement not found.');
        $type=$this->bookingType((string)($input['requirement_type']??($current['requirement_type']??'other')));$title=$this->clip((string)($input['title']??($current['title']??'')),180);if($title==='')throw new InvalidArgumentException('Requirement title is required.');
        $key=$current?(string)$current['requirement_key']:$this->requirementKey((string)($input['requirement_key']??$title));$required=!empty($input['is_required'])?1:0;$status=$this->requirementStatus((string)($input['status']??($current['status']??'open')));$due=$this->dateTimeOrNull((string)($input['due_at']??($current['due_at']??'')));$notes=$this->nullableClip((string)($input['notes']??($current['notes']??'')),700);$sort=max(0,min(999,(int)($input['sort_order']??($current['sort_order']??100))));
        if($id>0){$stmt=$this->pdo->prepare('UPDATE trip_readiness_requirements SET requirement_type=?,title=?,is_required=?,status=?,due_at=?,notes=?,sort_order=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?');$stmt->execute([$type,$title,$required,$status,$due,$notes,$sort,$id,$userId,$tripId]);}
        else{$stmt=$this->pdo->prepare('INSERT INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,due_at,notes,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');$stmt->execute([$userId,$tripId,$key,$type,$title,$required,$status,$due,$notes,$sort]);$id=(int)$this->pdo->lastInsertId();}
        return $this->requirement($userId,$tripId,$id)??[];
    }

    public function syncApprovedHandoff(int $userId,int $tripId,int $actionId): ?array
    {
        if(!$this->ready()||$actionId<1)return null;$this->trip($userId,$tripId);
        $stmt=$this->pdo->prepare("SELECT e.id,e.proposal_json,e.updated_at,a.title FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id WHERE e.user_id=? AND e.dream_trip_id=? AND e.action_id=? AND e.proposal_type='booking_handoff' AND e.status='completed' LIMIT 1");$stmt->execute([$userId,$tripId,$actionId]);$row=$stmt->fetch();if(!$row)return null;
        $executionId=(int)$row['id'];$existing=$this->bookingByExecution($userId,$tripId,$executionId);if($existing)return $existing;$proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))$proposal=[];
        $itemType=(string)($proposal['item_type']??'other');$type=match($itemType){'flight'=>'flight','hotel'=>'lodging','food'=>'restaurant','activity','experience'=>'activity',default=>'other'};$title=$this->clip((string)($proposal['title']??$row['title']??'Booking handoff'),180);
        $notes=$this->clip(trim((string)($proposal['notes']??'')).' '.trim((string)($proposal['approval_note']??'Confirm live availability, final price, terms, and payment with the provider before booking.')),4000);$amount=$this->moneyOrNull($proposal['price']??null);$url=$this->nullableClip((string)($proposal['source_url']??''),1500);if($url!==null&&!preg_match('#^https?://#i',$url))$url=null;
        $scheduled=$this->dateTimeOrNull((string)($proposal['scheduled_date']??''));$insert=$this->pdo->prepare("INSERT IGNORE INTO trip_bookings (user_id,dream_trip_id,source_execution_id,booking_type,title,status,payment_status,amount,currency,starts_at,provider_url,notes,source,requires_live_confirmation,created_at,updated_at) VALUES (?,?,?,?,?,'ready_to_book','unknown',?,?,?,?,?,'agent_handoff',1,?,?)");
        $currency=$this->currency((string)($proposal['currency']??'USD'));$insert->execute([$userId,$tripId,$executionId,$type,$title,$amount,$currency,$scheduled,$url,$notes,$row['updated_at'],$row['updated_at']]);$created=$this->bookingByExecution($userId,$tripId,$executionId);if($created&&$insert->rowCount()>0)$this->event((int)$created['id'],$userId,$tripId,'handoff_created',['action_id'=>$actionId,'title'=>$title]);return $created;
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;$trips=$snapshot['upcoming_trips']??[];
        foreach($trips as &$trip){$tripId=(int)($trip['id']??0);if($tripId<1)continue;try{$r=$this->readiness($userId,$tripId);$trip['booking_readiness']=$r['percent'];$trip['booking_summary']=$r;$trip['booking_url']=app_url('trip-bookings.php?id='.$tripId);}catch(Throwable $e){error_log('Trip readiness augmentation failed: '.$e->getMessage());}}unset($trip);$snapshot['upcoming_trips']=$trips;
        $existing=is_array($snapshot['booking_handoffs']??null)?$snapshot['booking_handoffs']:[];$managed=$this->commandHandoffs($userId);$snapshot['booking_handoffs']=$this->mergeHandoffs($existing,$managed);$snapshot['summary']['booking_handoffs']=count($snapshot['booking_handoffs']);
        $deadlineAttention=$this->commandAttention($userId);$attention=array_merge($deadlineAttention,is_array($snapshot['attention']??null)?$snapshot['attention']:[]);usort($attention,fn($a,$b)=>(int)($b['priority']??0)<=>(int)($a['priority']??0));$snapshot['attention']=array_slice($attention,0,8);$snapshot['summary']['booking_deadlines']=count($deadlineAttention);$snapshot['summary']['needs_you']=count($snapshot['attention']);if($snapshot['summary']['needs_you']>0)$snapshot['status']='Needs your attention';return $snapshot;
    }

    private function ensureDefaults(int $userId,int $tripId,array $trip): void
    {
        $defaults=[
            ['flight','flight','Flights',(!empty($trip['origin_iata'])&&!empty($trip['destination_iata']))?1:0,10],
            ['lodging','lodging','Lodging',(!empty($trip['start_date'])&&!empty($trip['end_date'])&&strtotime((string)$trip['end_date'])>strtotime((string)$trip['start_date']))?1:0,20],
            ['transport','transport','Local transportation',0,30],['events','event','Events & tickets',0,40],['restaurants','restaurant','Restaurant reservations',0,50],['activities','activity','Activities',0,60],['documents','document','Documents & reminders',0,70],
        ];
        $stmt=$this->pdo->prepare("INSERT IGNORE INTO trip_readiness_requirements (user_id,dream_trip_id,requirement_key,requirement_type,title,is_required,status,sort_order) VALUES (?,?,?,?,?,?,'open',?)");foreach($defaults as $d)$stmt->execute([$userId,$tripId,$d[0],$d[1],$d[2],$d[3],$d[4]]);
    }

    private function syncHistoricalHandoffs(int $userId,int $tripId): void
    {
        if(!db_table_exists('trip_agent_action_executions'))return;$stmt=$this->pdo->prepare("SELECT action_id FROM trip_agent_action_executions WHERE user_id=? AND dream_trip_id=? AND proposal_type='booking_handoff' AND status='completed' ORDER BY id DESC LIMIT 50");$stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $actionId){try{$this->syncApprovedHandoff($userId,$tripId,(int)$actionId);}catch(Throwable $e){error_log('Booking handoff sync failed: '.$e->getMessage());}}
    }

    private function calculateReadiness(array $bookings,array $requirements): array
    {
        $best=[];$rank=['confirmed'=>100,'booked'=>90,'changed'=>45,'ready_to_book'=>35,'unbooked'=>0,'cancelled'=>0];
        foreach($bookings as $booking){$type=(string)$booking['booking_type'];$score=$rank[(string)$booking['status']]??0;if(in_array((string)$booking['status'],['confirmed','booked'],true)&&in_array((string)$booking['payment_status'],['unpaid'],true))$score=max(0,$score-10);if(!isset($best[$type])||$score>$best[$type]['score'])$best[$type]=['score'=>$score,'booking'=>$booking];}
        $required=0;$points=0;$secured=0;$outstanding=0;$categories=[];
        foreach($requirements as $req){$isRequired=(int)$req['is_required']===1;$type=(string)$req['requirement_type'];$reqStatus=(string)$req['status'];$bookingScore=(int)($best[$type]['score']??0);$score=($reqStatus==='complete'||$reqStatus==='not_needed')?100:$bookingScore;if($isRequired){$required++;$points+=$score;if($score>=90)$secured++;else$outstanding++;}$categories[]=['id'=>(int)$req['id'],'key'=>(string)$req['requirement_key'],'type'=>$type,'title'=>(string)$req['title'],'required'=>$isRequired,'status'=>$reqStatus,'score'=>$score,'booking_status'=>$best[$type]['booking']['status']??null,'due_at'=>$req['due_at']??null];}
        $percent=$required>0?(int)round($points/$required):0;$now=time();$week=$now+7*86400;$threeDays=$now+3*86400;$deadlineSoon=0;$checkinSoon=0;$paymentDue=0;$attention=0;$readyToBook=0;$confirmed=0;$bookedCount=0;$nextDeadline=null;
        foreach($bookings as $b){$status=(string)$b['status'];if($status==='ready_to_book')$readyToBook++;if($status==='confirmed')$confirmed++;if(in_array($status,['booked','confirmed'],true))$bookedCount++;if(in_array($status,['changed','cancelled'],true))$attention++;if(in_array($status,['booked','confirmed'],true)&&in_array((string)$b['payment_status'],['unpaid','deposit_paid'],true))$paymentDue++;
            $cancel=$this->ts($b['cancellation_deadline']??null);if($cancel&&$cancel>=$now&&$cancel<=$week&&$status!=='cancelled'){$deadlineSoon++;if($nextDeadline===null||$cancel<$nextDeadline)$nextDeadline=$cancel;}$check=$this->ts($b['checkin_opens_at']??null);if($check&&$check>=$now&&$check<=$threeDays&&in_array($status,['booked','confirmed'],true)){$checkinSoon++;if($nextDeadline===null||$check<$nextDeadline)$nextDeadline=$check;}}
        foreach($requirements as $req){$due=$this->ts($req['due_at']??null);if($due&&$due>=$now&&$due<=$week&&(string)$req['status']==='open'&&(int)$req['is_required']===1){$deadlineSoon++;if($nextDeadline===null||$due<$nextDeadline)$nextDeadline=$due;}}
        $label=$required===0?'Set requirements':($percent>=95?'Ready to travel':($percent>=75?'Nearly ready':($percent>=40?'Booking in progress':'Needs booking')));
        return ['percent'=>max(0,min(100,$percent)),'status_label'=>$label,'required'=>$required,'secured'=>$secured,'outstanding'=>$outstanding,'bookings_total'=>count($bookings),'booked'=>$bookedCount,'confirmed'=>$confirmed,'ready_to_book'=>$readyToBook,'attention'=>$attention,'payment_due'=>$paymentDue,'deadlines_soon'=>$deadlineSoon,'checkins_soon'=>$checkinSoon,'next_deadline'=>$nextDeadline?date('Y-m-d H:i:s',$nextDeadline):null,'categories'=>$categories];
    }

    private function commandHandoffs(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT b.id,b.dream_trip_id,b.status,b.title,b.updated_at,b.requires_live_confirmation,dt.name trip_name FROM trip_bookings b JOIN dream_trips dt ON dt.id=b.dream_trip_id WHERE b.user_id=? AND b.status IN ('ready_to_book','changed') ORDER BY FIELD(b.status,'changed','ready_to_book'),b.updated_at DESC,b.id DESC LIMIT 8");$stmt->execute([$userId]);$out=[];foreach($stmt->fetchAll()?:[] as $row){$tripId=(int)$row['dream_trip_id'];$out[]=['id'=>'booking:'.(int)$row['id'],'trip_id'=>$tripId,'trip_name'=>(string)$row['trip_name'],'status'=>(string)$row['status'],'title'=>(string)$row['title'],'approval_note'=>(string)$row['status']==='changed'?'This reservation changed. Reconfirm live details before relying on it.':'Ready to book. Confirm live availability, final price, terms, and payment with the provider.','updated_at'=>(string)$row['updated_at'],'url'=>app_url('trip-bookings.php?id='.$tripId)];}return $out;
    }

    private function commandAttention(int $userId): array
    {
        $out=[];$now=date('Y-m-d H:i:s');$week=date('Y-m-d H:i:s',time()+7*86400);
        $stmt=$this->pdo->prepare("SELECT b.id,b.dream_trip_id,b.title,b.status,b.cancellation_deadline,b.checkin_opens_at,dt.name trip_name FROM trip_bookings b JOIN dream_trips dt ON dt.id=b.dream_trip_id WHERE b.user_id=? AND (b.status='changed' OR (b.cancellation_deadline BETWEEN ? AND ? AND b.status<>'cancelled') OR (b.checkin_opens_at BETWEEN ? AND ? AND b.status IN ('booked','confirmed'))) ORDER BY b.updated_at DESC,b.id DESC LIMIT 8");$stmt->execute([$userId,$now,$week,$now,date('Y-m-d H:i:s',time()+3*86400)]);
        foreach($stmt->fetchAll()?:[] as $row){$tripId=(int)$row['dream_trip_id'];$kind=(string)$row['status']==='changed'?'booking':'deadline';$title=$kind==='booking'?'Reservation changed':'Booking deadline approaching';$body=(string)$row['title'].' · '.(string)$row['trip_name'];$out[]=['key'=>'booking:'.(int)$row['id'],'kind'=>'risk','priority'=>$kind==='booking'?96:88,'title'=>$title,'body'=>$body,'trip_name'=>(string)$row['trip_name'],'url'=>app_url('trip-bookings.php?id='.$tripId),'cta'=>'Review booking'];}return $out;
    }

    private function mergeHandoffs(array $a,array $b): array
    {
        $out=[];$seen=[];foreach(array_merge($b,$a) as $row){$key=(string)($row['id']??'').':'.(int)($row['trip_id']??0);if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$row;if(count($out)>=8)break;}return $out;
    }

    private function booking(int $userId,int $tripId,int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$id,$userId,$tripId]);$row=$stmt->fetch();return $row?$this->decorateBooking($row):null;
    }

    private function bookingByExecution(int $userId,int $tripId,int $executionId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM trip_bookings WHERE source_execution_id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$executionId,$userId,$tripId]);$row=$stmt->fetch();return $row?$this->decorateBooking($row):null;
    }

    private function requirement(int $userId,int $tripId,int $id): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM trip_readiness_requirements WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$id,$userId,$tripId]);return $stmt->fetch()?:null;
    }

    private function trip(int $userId,int $tripId): array
    {
        if($tripId<1)throw new InvalidArgumentException('Trip is required.');$stmt=$this->pdo->prepare('SELECT id,user_id,name,status,start_date,end_date,target_budget,booking_readiness,metadata_json'.(db_column_exists('dream_trips','origin_iata')?',origin_iata,destination_iata,currency':'').' FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$stmt->execute([$tripId,$userId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Trip not found.');$meta=json_decode((string)($row['metadata_json']??''),true)?:[];$row['destination_name']=(string)($meta['destination_name']??'');$row['currency']=(string)($row['currency']??'USD');return $row;
    }

    private function decorateBooking(array $row): array
    {
        $row['id']=(int)$row['id'];$row['dream_trip_id']=(int)$row['dream_trip_id'];$row['source_execution_id']=$row['source_execution_id']!==null?(int)$row['source_execution_id']:null;$row['amount']=$row['amount']!==null?(float)$row['amount']:null;$row['requires_live_confirmation']=(int)$row['requires_live_confirmation']===1;return $row;
    }

    private function event(int $bookingId,int $userId,int $tripId,string $type,array $detail): void
    {
        if($bookingId<1||!$this->ready())return;$json=json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);$this->pdo->prepare('INSERT INTO trip_booking_events (booking_id,user_id,dream_trip_id,event_type,detail_json) VALUES (?,?,?,?,?)')->execute([$bookingId,$userId,$tripId,$type,$json===false?'{}':$json]);
    }

    private function bookingType(string $value): string{$value=strtolower(trim($value));return in_array($value,self::BOOKING_TYPES,true)?$value:'other';}
    private function bookingStatus(string $value): string{$value=strtolower(trim($value));if(!in_array($value,self::BOOKING_STATUSES,true))throw new InvalidArgumentException('Invalid booking status.');return $value;}
    private function paymentStatus(string $value): string{$value=strtolower(trim($value));if(!in_array($value,self::PAYMENT_STATUSES,true))throw new InvalidArgumentException('Invalid payment status.');return $value;}
    private function requirementStatus(string $value): string{$value=strtolower(trim($value));if(!in_array($value,self::REQUIREMENT_STATUSES,true))throw new InvalidArgumentException('Invalid requirement status.');return $value;}
    private function currency(string $value): string{$value=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$value)?$value:'USD';}
    private function moneyOrNull(mixed $value): ?float{if($value===null||trim((string)$value)==='')return null;$n=(float)$value;if($n<0)throw new InvalidArgumentException('Booking amount cannot be negative.');return round($n,2);}
    private function requirementKey(string $value): string{$value=strtolower(trim($value));$value=preg_replace('/[^a-z0-9]+/','-',$value)??'';$value=trim($value,'-');return substr($value!==''?$value:'requirement-'.bin2hex(random_bytes(4)),0,80);}
    private function dateTimeOrNull(string $value): ?string{$value=trim($value);if($value==='')return null;$ts=strtotime($value);if($ts===false)throw new InvalidArgumentException('Use a valid date and time.');return date('Y-m-d H:i:s',$ts);}
    private function ts(mixed $value): ?int{$value=trim((string)$value);if($value==='')return null;$ts=strtotime($value);return $ts===false?null:$ts;}
    private function nullableClip(string $value,int $max): ?string{$value=$this->clip($value,$max);return $value===''?null:$value;}
    private function clip(string $value,int $max): string{$value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Booking + Trip Readiness.');}
    private function emptyReadiness(): array{return ['percent'=>0,'status_label'=>'Upgrade required','required'=>0,'secured'=>0,'outstanding'=>0,'bookings_total'=>0,'booked'=>0,'confirmed'=>0,'ready_to_book'=>0,'attention'=>0,'payment_due'=>0,'deadlines_soon'=>0,'checkins_soon'=>0,'next_deadline'=>null,'categories'=>[]];}
}
