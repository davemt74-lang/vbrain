<?php
declare(strict_types=1);

/**
 * Recovery & Rebooking Intelligence over canonical saved trip state.
 *
 * Detection and research are deliberately separate from transaction execution.
 * This service may read live provider search data only after an explicit planning
 * refresh, persist public-safe recovery candidates, and prepare an existing
 * provider-handoff booking flow after an explicit owner action. It never books,
 * cancels, refunds, pays, checks out, or mutates a provider directly.
 */
final class TripRecoveryIntelligenceService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripItineraryIntelligenceService')
            && db_table_exists('trip_recovery_incidents')
            && db_table_exists('trip_recovery_options')
            && db_table_exists('trip_recovery_events')
            && db_column_exists('dream_trips','recovery_checked_at');
    }

    public function snapshot(int $userId,int $tripId,bool $refreshProviders=false,bool $sync=true): array
    {
        $itineraryService=new TripItineraryIntelligenceService($this->pdo);
        $access=$itineraryService->access($userId,$tripId);
        $trip=$this->trip($tripId);
        if(!$this->ready())return $this->emptySnapshot($trip,$access);

        if($sync&&!empty($access['can_plan'])){
            $this->detect($userId,$tripId,$trip,$access);
            if($refreshProviders)$this->refreshProviderResearch($userId,$tripId,$trip,$access);
            $this->pdo->prepare('UPDATE dream_trips SET recovery_checked_at=NOW() WHERE id=?')->execute([$tripId]);
        }

        $incidents=$this->incidents($tripId);
        $options=$this->options($tripId);
        $byIncident=[];foreach($options as $option)$byIncident[(int)$option['incident_id']][]=$option;
        foreach($incidents as &$incident)$incident['options']=$byIncident[(int)$incident['id']]??[];unset($incident);
        if(($access['role']??'viewer')!=='viewer')$this->syncInbox($userId,$tripId,$incidents);

        $summary=[
            'open'=>count(array_filter($incidents,static fn(array $i):bool=>in_array($i['status'],['open','reviewed'],true))),
            'critical'=>count(array_filter($incidents,static fn(array $i):bool=>$i['status']!=='resolved'&&$i['severity']==='critical')),
            'high'=>count(array_filter($incidents,static fn(array $i):bool=>$i['status']!=='resolved'&&$i['severity']==='high')),
            'options'=>count(array_filter($options,static fn(array $o):bool=>in_array($o['status'],['active','selected'],true))),
            'provider_options'=>count(array_filter($options,static fn(array $o):bool=>!empty($o['provider_observed_at'])&&$o['status']!=='dismissed')),
        ];
        return [
            'ready'=>true,'generated_at'=>date(DATE_ATOM),'trip'=>$this->publicTrip($trip),
            'role'=>(string)($access['role']??'viewer'),'can_plan'=>!empty($access['can_plan']),
            'can_prepare'=>!empty($access['is_owner']),'incidents'=>$incidents,'summary'=>$summary,
            'provider_refresh'=>$refreshProviders,
            'safety_note'=>'Recovery options are research and approval preparation only. Vacation Brain does not automatically rebook, cancel, purchase, refund, pay, or change a provider reservation.',
        ];
    }

    public function refresh(int $userId,int $tripId): array
    {
        $this->requireReady();
        $access=(new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);
        if(empty($access['can_plan']))throw new DomainException('Only the trip owner or a Co-planner can refresh shared recovery research.');
        $snap=$this->snapshot($userId,$tripId,true,true);
        $this->event($tripId,null,null,$userId,'refreshed',['provider_research'=>true]);
        return $snap;
    }

    public function setIncidentStatus(int $userId,int $tripId,int $incidentId,string $status): void
    {
        $this->requireReady();$access=(new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);
        if(empty($access['can_plan']))throw new DomainException('Only the trip owner or a Co-planner can update shared recovery state.');
        if(!in_array($status,['reviewed','resolved','dismissed'],true))throw new InvalidArgumentException('Unknown recovery status.');
        $q=$this->pdo->prepare('SELECT id FROM trip_recovery_incidents WHERE id=? AND dream_trip_id=? LIMIT 1');$q->execute([$incidentId,$tripId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Recovery incident not found.');
        $resolved=in_array($status,['resolved','dismissed'],true)?date('Y-m-d H:i:s'):null;
        $this->pdo->prepare('UPDATE trip_recovery_incidents SET status=?,resolved_at=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?')->execute([$status,$resolved,$incidentId,$tripId]);
        $this->event($tripId,$incidentId,null,$userId,$status,[]);
    }

    /**
     * Explicit owner-only bridge into the existing provider-handoff approval flow.
     * This creates a Ready to Book candidate and an awaiting-approval handoff quote;
     * it does not open checkout and does not execute a provider transaction.
     */
    public function prepareOption(int $userId,int $tripId,int $optionId): array
    {
        $this->requireReady();$access=(new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);
        if(empty($access['is_owner']))throw new DomainException('Only the trip owner can prepare a recovery booking handoff.');
        $q=$this->pdo->prepare("SELECT o.*,i.status incident_status FROM trip_recovery_options o JOIN trip_recovery_incidents i ON i.id=o.incident_id WHERE o.id=? AND o.dream_trip_id=? LIMIT 1");$q->execute([$optionId,$tripId]);$option=$q->fetch();
        if(!$option)throw new OutOfBoundsException('Recovery option not found.');
        if(!in_array((string)$option['status'],['active','selected'],true))throw new DomainException('This recovery option is no longer active.');
        if(!empty($option['expires_at'])&&strtotime((string)$option['expires_at'])<=time())throw new DomainException('This provider recovery result is stale. Refresh recovery options before preparing checkout.');
        if(empty($option['transaction_required'])||empty($option['booking_type']))throw new DomainException('This recovery option is planning guidance, not a booking handoff.');
        $url=$this->externalUrl((string)($option['source_url']??''));if($url===null)throw new DomainException('This option has no verified provider checkout URL. Refresh provider research or open the provider directly.');
        if(!class_exists('TripBookingService')||!class_exists('TripBookingActionService'))throw new RuntimeException('Booking & Action Execution is unavailable.');

        $bookingService=new TripBookingService($this->pdo);
        $booking=$bookingService->saveBooking($userId,$tripId,[
            'booking_type'=>(string)$option['booking_type'],'title'=>(string)$option['title'],
            'provider_name'=>(string)($option['provider_name']??''),'status'=>'ready_to_book','payment_status'=>'unknown',
            'amount'=>$option['amount'],'currency'=>(string)($option['currency']?:'USD'),
            'starts_at'=>(string)($option['starts_at']??''),'ends_at'=>(string)($option['ends_at']??''),
            'provider_url'=>$url,
            'notes'=>'Prepared from Vacation Brain Recovery & Rebooking Intelligence. This is a candidate only; final availability, price, terms, traveler details, and payment remain on the provider site.',
        ]);
        $bookingId=(int)($booking['id']??0);if($bookingId<1)throw new RuntimeException('Recovery booking candidate could not be created.');
        $actions=new TripBookingActionService($this->pdo);$intent=$actions->ensureHandoffIntent($userId,$tripId,$bookingId);$intentId=(int)($intent['id']??0);
        if($intentId>0)$intent=$actions->prepareHandoff($userId,$tripId,$intentId);
        $this->pdo->prepare("UPDATE trip_recovery_options SET status='selected',prepared_booking_id=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?")->execute([$bookingId,$optionId,$tripId]);
        $this->pdo->prepare("UPDATE trip_recovery_incidents SET status='reviewed',updated_at=NOW() WHERE id=? AND dream_trip_id=? AND status='open'")->execute([(int)$option['incident_id'],$tripId]);
        $this->event($tripId,(int)$option['incident_id'],$optionId,$userId,'booking_prepared',['booking_id'=>$bookingId,'intent_id'=>$intentId]);
        return ['booking'=>$booking,'intent'=>$intent];
    }

    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limitTrips=max(1,min(5,$limitTrips));$ids=[];
        $q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY FIELD(operational_state,'traveling','ready','booking','planning','completed'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limitTrips}");$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if(count($ids)<$limitTrips&&db_table_exists('trip_collaborators')){$remain=$limitTrips-count($ids);$s=$this->pdo->prepare("SELECT tc.dream_trip_id FROM trip_collaborators tc JOIN dream_trips dt ON dt.id=tc.dream_trip_id WHERE tc.user_id=? AND tc.status='active' AND dt.status<>'abandoned' AND (dt.end_date IS NULL OR dt.end_date>=CURDATE()) ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.id LIMIT {$remain}");$s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ids[]=(int)$id;}
        $out=[];foreach(array_values(array_unique($ids)) as $tripId){try{$access=(new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);}catch(Throwable){continue;}$trip=$this->trip($tripId);$inc=array_values(array_filter($this->incidents($tripId),static fn(array $i):bool=>in_array($i['status'],['open','reviewed'],true)));if(!$inc)continue;$tops=[];foreach(array_slice($inc,0,4) as $i)$tops[]=['type'=>$i['incident_type'],'severity'=>$i['severity'],'title'=>$i['title'],'status'=>$i['status']];$out[]=['trip_id'=>$tripId,'trip_name'=>(string)$trip['name'],'role'=>(string)($access['role']??'viewer'),'open_incidents'=>count($inc),'incidents'=>$tops];}
        return $out;
    }

    /** Background detection reuses the existing travel-watch worker and never refreshes providers. */
    public function runUpcoming(int $limit=25): array
    {
        if(!$this->ready())return ['checked'=>0,'incidents'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));
        $rows=$this->pdo->query("SELECT id,user_id FROM dream_trips WHERE status<>'abandoned' AND operational_state IN ('ready','traveling') AND (end_date IS NULL OR end_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) ORDER BY COALESCE(recovery_checked_at,'1970-01-01 00:00:00'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limit}")->fetchAll()?:[];$out=['checked'=>0,'incidents'=>0,'errors'=>0];
        foreach($rows as $row){$out['checked']++;try{$snap=$this->snapshot((int)$row['user_id'],(int)$row['id'],false,true);$out['incidents']+=(int)$snap['summary']['open'];}catch(Throwable $e){$out['errors']++;error_log('Recovery Intelligence sync failed: '.$e->getMessage());}}
        return $out;
    }

    private function detect(int $userId,int $tripId,array $trip,array $access): void
    {
        $active=[];$bookingRows=$this->bookings($tripId);
        foreach($bookingRows as $booking){
            $ops=(string)($booking['operational_status']??'unknown');if(!in_array($ops,['delayed','cancelled'],true))continue;
            $type=(string)$booking['booking_type'];$incidentType=$type==='flight'?($ops==='cancelled'?'flight_cancelled':'flight_delayed'):($type==='lodging'?'lodging_disruption':($type==='transport'?'transport_disruption':'general_disruption'));
            $severity=$ops==='cancelled'?'high':($type==='flight'?'high':'medium');$key='booking:'.(int)$booking['id'].':'.$ops;$active[]=$key;
            $this->upsertIncident($tripId,$key,$incidentType,'booking',(string)$booking['id'],$severity,(string)$booking['title'].' is '.($ops==='cancelled'?'cancelled':'delayed'),'The saved operational status for this '.ucfirst($type).' is '.($ops==='cancelled'?'cancelled':'delayed').'. Recovery Intelligence can research alternatives, but no provider change happens automatically.',(string)($booking['starts_at']??''),$userId);
            if($type==='flight'&&$ops==='delayed'&&!empty($booking['starts_at']))$this->detectTightConnection($tripId,$booking,$bookingRows,$active,$userId);
        }

        try{$itinerary=(new TripItineraryIntelligenceService($this->pdo))->snapshot($userId,$tripId,null,false);foreach((array)($itinerary['all_issues']??[]) as $issue){if(!in_array((string)($issue['severity']??''),['high','medium'],true))continue;$key='itinerary:'.(string)($issue['key']??sha1((string)($issue['title']??'')));$active[]=$key;$this->upsertIncident($tripId,$key,'timing_conflict','itinerary',(string)($issue['key']??''),(string)$issue['severity'],(string)($issue['title']??'Trip timing conflict'),(string)($issue['body']??'The saved itinerary has a timing conflict that may need recovery planning.'),(string)($issue['starts_at']??''),$userId);}}catch(Throwable $e){error_log('Recovery itinerary detection failed: '.$e->getMessage());}

        try{if(class_exists('TripTravelDayCopilotService')){$copilot=new TripTravelDayCopilotService($this->pdo);if($copilot->ready()){$c=$copilot->snapshot($userId,$tripId,null,false);foreach((array)($c['ripples']??[]) as $r){$rawKey=(string)($r['key']??'');if(!str_starts_with($rawKey,'late:')&&!str_starts_with($rawKey,'help:')&&!str_starts_with($rawKey,'leave-overdue:'))continue;$incidentType=str_starts_with($rawKey,'help:')?'help_requested':'traveler_delay';$key='copilot:'.$rawKey;$active[]=$key;$this->upsertIncident($tripId,$key,$incidentType,'traveler',$rawKey,(string)($r['severity']??'medium'),(string)($r['title']??'Traveler timing needs attention'),(string)($r['body']??'Traveler-declared progress indicates a recovery check may be useful.'),null,$userId);}}}}catch(Throwable $e){error_log('Recovery Copilot detection failed: '.$e->getMessage());}

        $this->resolveMissingDerived($tripId,$active);
        foreach($this->incidents($tripId) as $incident)if(in_array($incident['status'],['open','reviewed'],true))$this->ensureBaselineOptions($tripId,$incident,$bookingRows);
    }

    private function detectTightConnection(int $tripId,array $flight,array $rows,array &$active,int $userId): void
    {
        $start=strtotime((string)$flight['starts_at']);if(!$start)return;$next=null;
        foreach($rows as $row){if((int)$row['id']===(int)$flight['id']||empty($row['starts_at'])||in_array((string)$row['status'],['cancelled','unbooked'],true))continue;$ts=strtotime((string)$row['starts_at']);if(!$ts||$ts<=$start)continue;if($next===null||$ts<$next['ts'])$next=['row'=>$row,'ts'=>$ts];}
        if(!$next)return;$gap=(int)round(($next['ts']-$start)/60);if($gap>240)return;$key='connection:'.(int)$flight['id'].':'.(int)$next['row']['id'];$active[]=$key;$severity=$gap<=120?'high':'medium';
        $this->upsertIncident($tripId,$key,'missed_connection_risk','booking',(string)$flight['id'],$severity,'Delayed flight may put the next commitment at risk','The next saved commitment starts about '.$gap.' minutes after the delayed flight’s scheduled time. Treat this as a connection/timing risk until live provider timing is confirmed.',(string)$next['row']['starts_at'],$userId);
    }

    private function refreshProviderResearch(int $userId,int $tripId,array $trip,array $access): void
    {
        if(empty($access['can_plan']))return;$provider=new LiveTravelDataProviderService($this->pdo);$open=$this->incidents($tripId);$flightInc=[];$lodgingInc=[];
        foreach($open as $i){if(!in_array($i['status'],['open','reviewed'],true))continue;if(in_array($i['incident_type'],['flight_cancelled','flight_delayed','missed_connection_risk'],true))$flightInc[]=$i;if($i['incident_type']==='lodging_disruption')$lodgingInc[]=$i;}
        $providerTrip=$trip;$providerTrip['id']=$tripId;
        try{$weather=$provider->weather($providerTrip);if(!empty($weather['ok'])){foreach(array_slice((array)($weather['alerts']??[]),0,5) as $alert){$title=trim((string)($alert['headline']??$alert['event']??'Weather disruption'));$body=trim((string)($alert['description']??''));$key='weather:'.sha1($title.'|'.$body);$this->upsertIncident($tripId,$key,'weather_disruption','provider','weather','high',$title?:'Weather disruption',$body?:'A live weather alert may affect this trip.',null,$userId);}}}catch(Throwable $e){error_log('Recovery weather research failed: '.$e->getMessage());}
        if($flightInc){try{$flights=$provider->flights($providerTrip);if(!empty($flights['ok']))foreach($flightInc as $incident)$this->storeFlightResearch($tripId,$incident,$flights);}catch(Throwable $e){error_log('Recovery flight research failed: '.$e->getMessage());}}
        if($lodgingInc){try{$lodging=$provider->lodging($providerTrip);if(!empty($lodging['ok']))foreach($lodgingInc as $incident)$this->storeLodgingResearch($tripId,$incident,$lodging);}catch(Throwable $e){error_log('Recovery lodging research failed: '.$e->getMessage());}}
        foreach($this->incidents($tripId) as $incident)if(in_array($incident['status'],['open','reviewed'],true))$this->ensureBaselineOptions($tripId,$incident,$this->bookings($tripId));
    }

    private function ensureBaselineOptions(int $tripId,array $incident,array $bookings): void
    {
        $id=(int)$incident['id'];$type=(string)$incident['incident_type'];$internal=app_url('trip-recovery.php?id='.$tripId);
        if(in_array($type,['flight_cancelled','flight_delayed','missed_connection_risk'],true)){
            $booking=$this->sourceBooking($incident,$bookings);if($booking&&($url=$this->externalUrl((string)($booking['provider_url']??''))))$this->upsertOption($tripId,$id,'provider-support','provider_support','Open '.((string)($booking['provider_name']??'')?:'the provider').' rebooking/support','Open the saved provider page to review rebooking or support options. Any change is completed with the provider, not by Vacation Brain.',(string)($booking['provider_name']??''),$url,null,null,null,null,null,true,null,null,null,['research_only'=>false]);
            $this->upsertOption($tripId,$id,'protect-next','protect_next_booking','Protect the next fixed commitment','Review the next fixed booking or reservation before changing the current leg. Vacation Brain can show timing conflicts, but will not change either reservation automatically.',null,app_url('trip-itinerary.php?id='.$tripId),null,null,null,null,null,false,null,null,null,['planning_only'=>true]);
        }elseif($type==='lodging_disruption'){
            $this->upsertOption($tripId,$id,'lodging-research','alternate_lodging','Refresh live lodging alternatives','Use the Recovery Center refresh to load current Booking.com Demand API accommodation results when configured. No room is reserved until a separate provider-handoff approval and checkout.',null,$internal,null,null,'lodging',null,null,false,null,null,null,['planning_only'=>true]);
        }elseif($type==='transport_disruption'){
            $this->upsertOption($tripId,$id,'local-alternatives','alternate_transport','Review local transport alternatives','Open Local Concierge to research nearby transportation and local options. Suggestions are not reservations.',null,app_url('local-concierge.php?id='.$tripId),null,null,'transport',null,null,false,null,null,null,['planning_only'=>true]);
        }elseif($type==='timing_conflict'){
            $this->upsertOption($tripId,$id,'resequence','resequence','Recheck the door-to-door itinerary','Open Complete Itinerary Intelligence to review fixed anchors, transfer buffers and flexible sequencing before committing to a recovery action.',null,app_url('trip-itinerary.php?id='.$tripId),null,null,null,null,null,false,null,null,null,['planning_only'=>true]);
        }elseif($type==='weather_disruption'){
            $this->upsertOption($tripId,$id,'weather-plan','resequence','Review weather-sensitive timing','Recheck fixed reservations and flexible itinerary items around the saved provider weather alert. No reservation changes are automatic.',null,app_url('trip-itinerary.php?id='.$tripId),null,null,null,null,null,false,null,null,null,['planning_only'=>true]);
        }elseif(in_array($type,['traveler_delay','help_requested'],true)){
            $this->upsertOption($tripId,$id,'copilot-review','monitor','Return to Travel Day Copilot','Review Now / Next / Later, leave-by timing and the next fixed commitment before choosing a provider action.',null,app_url('travel-mode.php?id='.$tripId),null,null,null,null,null,false,null,null,null,['planning_only'=>true]);
        }else $this->upsertOption($tripId,$id,'monitor','monitor','Monitor and review','Keep the incident open while Vacation Brain watches saved trip facts for changes.',null,$internal,null,null,null,null,null,false,null,null,null,['planning_only'=>true]);
    }

    private function storeFlightResearch(int $tripId,array $incident,array $flights): void
    {
        $min=$flights['min_price']??null;$currency=(string)($flights['currency']??'USD');$summary='Skyscanner indicative replacement airfare research';if($min!==null)$summary.=' starts around '.$currency.' '.number_format((float)$min,2);$summary.='. These are planning estimates, not guaranteed bookable itineraries or live seat inventory.';
        $observed=$this->providerTime((string)($flights['observed_at']??''));$expires=$observed?date('Y-m-d H:i:s',strtotime($observed)+max(300,(int)($flights['expires_in']??21600))):date('Y-m-d H:i:s',time()+21600);
        $this->upsertOption($tripId,(int)$incident['id'],'live-airfare','alternate_flight','Indicative replacement airfare',$summary,(string)($flights['provider']??'Skyscanner'),null,$min,$currency,'flight',null,null,false,$observed,$expires,null,['quote_count'=>(int)($flights['quote_count']??0),'direct_available'=>!empty($flights['direct_available']),'accuracy_note'=>(string)($flights['accuracy_note']??'')]);
    }

    private function storeLodgingResearch(int $tripId,array $incident,array $lodging): void
    {
        $observed=$this->providerTime((string)($lodging['observed_at']??''));$expires=$observed?date('Y-m-d H:i:s',strtotime($observed)+max(300,(int)($lodging['expires_in']??1800))):date('Y-m-d H:i:s',time()+1800);$n=0;
        foreach((array)($lodging['items']??[]) as $item){if($n++>=6)break;$url=$this->externalUrl((string)($item['url']??''));if($url===null)continue;$amount=$item['price_total']??$item['price_display']??null;$currency=(string)($item['currency']??$lodging['currency']??'USD');$name=(string)($item['name']??'Alternate lodging');
            $this->upsertOption($tripId,(int)$incident['id'],'booking-com:'.(string)($item['id']??sha1($name.$url)),'alternate_lodging',$name,'Live Booking.com accommodation research for the saved trip dates. Availability, taxes, room terms and final price can change before provider checkout.','Booking.com Demand API',$url,$amount,$currency,'lodging',(string)($item['checkin']??'').' 15:00:00',(string)($item['checkout']??'').' 11:00:00',true,$observed,$expires,null,['address'=>(string)($item['address']??''),'available'=>!empty($item['available'])]);
        }
    }

    private function upsertIncident(int $tripId,string $key,string $type,string $sourceType,?string $sourceKey,string $severity,string $title,string $summary,?string $startsAt,int $userId): int
    {
        $severity=in_array($severity,['low','medium','high','critical'],true)?$severity:'medium';$starts=$this->dateTimeOrNull($startsAt);
        $sql="INSERT INTO trip_recovery_incidents (dream_trip_id,incident_key,incident_type,source_type,source_key,severity,status,title,summary,starts_at,detected_at,last_seen_at) VALUES (?,?,?,?,?,?,'open',?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE incident_type=VALUES(incident_type),source_type=VALUES(source_type),source_key=VALUES(source_key),severity=VALUES(severity),title=VALUES(title),summary=VALUES(summary),starts_at=VALUES(starts_at),status=IF(status IN ('resolved','dismissed'),'open',status),resolved_at=IF(status IN ('resolved','dismissed'),NULL,resolved_at),last_seen_at=NOW(),updated_at=NOW()";
        $this->pdo->prepare($sql)->execute([$tripId,$key,$type,$sourceType,$sourceKey,$severity,$this->clip($title,220),$this->clip($summary,1200),$starts]);
        $q=$this->pdo->prepare('SELECT id FROM trip_recovery_incidents WHERE dream_trip_id=? AND incident_key=? LIMIT 1');$q->execute([$tripId,$key]);$id=(int)$q->fetchColumn();if($id>0)$this->event($tripId,$id,null,$userId,'detected',['incident_type'=>$type,'severity'=>$severity]);return $id;
    }

    private function upsertOption(int $tripId,int $incidentId,string $key,string $type,string $title,string $summary,?string $provider,?string $url,mixed $amount,?string $currency,?string $bookingType,?string $startsAt,?string $endsAt,bool $transactionRequired,?string $observedAt,?string $expiresAt,?int $preparedBookingId,array $payload): void
    {
        $money=is_numeric($amount)?round((float)$amount,2):null;$cur=$currency?strtoupper(substr(trim($currency),0,3)):null;$payloadJson=$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE):null;
        $sql="INSERT INTO trip_recovery_options (dream_trip_id,incident_id,option_key,option_type,status,title,summary,provider_name,source_url,amount,currency,booking_type,starts_at,ends_at,transaction_required,provider_observed_at,expires_at,prepared_booking_id,public_payload_json) VALUES (?,?,?,?,'active',?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE option_type=VALUES(option_type),status=IF(status='selected','selected','active'),title=VALUES(title),summary=VALUES(summary),provider_name=VALUES(provider_name),source_url=VALUES(source_url),amount=VALUES(amount),currency=VALUES(currency),booking_type=VALUES(booking_type),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),transaction_required=VALUES(transaction_required),provider_observed_at=VALUES(provider_observed_at),expires_at=VALUES(expires_at),prepared_booking_id=COALESCE(prepared_booking_id,VALUES(prepared_booking_id)),public_payload_json=VALUES(public_payload_json),updated_at=NOW()";
        $this->pdo->prepare($sql)->execute([$tripId,$incidentId,$key,$type,$this->clip($title,220),$this->clip($summary,1400),$this->nullableClip($provider,180),$url,$money,$cur,$bookingType,$this->dateTimeOrNull($startsAt),$this->dateTimeOrNull($endsAt),$transactionRequired?1:0,$observedAt,$expiresAt,$preparedBookingId,$payloadJson]);
    }

    private function resolveMissingDerived(int $tripId,array $activeKeys): void
    {
        $sources=['booking','itinerary','traveler'];$params=[$tripId];$sql="UPDATE trip_recovery_incidents SET status='resolved',resolved_at=NOW(),updated_at=NOW() WHERE dream_trip_id=? AND status IN ('open','reviewed') AND source_type IN ('booking','itinerary','traveler')";
        if($activeKeys){$sql.=' AND incident_key NOT IN ('.implode(',',array_fill(0,count($activeKeys),'?')).')';$params=array_merge($params,$activeKeys);}$this->pdo->prepare($sql)->execute($params);
    }

    private function incidents(int $tripId): array
    {
        $q=$this->pdo->prepare("SELECT * FROM trip_recovery_incidents WHERE dream_trip_id=? ORDER BY FIELD(status,'open','reviewed','resolved','dismissed'),FIELD(severity,'critical','high','medium','low'),COALESCE(starts_at,'9999-12-31'),updated_at DESC,id DESC LIMIT 80");$q->execute([$tripId]);return $q->fetchAll()?:[];
    }

    private function options(int $tripId): array
    {
        $this->pdo->prepare("UPDATE trip_recovery_options SET status='stale',updated_at=NOW() WHERE dream_trip_id=? AND status='active' AND expires_at IS NOT NULL AND expires_at<=NOW()")->execute([$tripId]);
        $q=$this->pdo->prepare("SELECT * FROM trip_recovery_options WHERE dream_trip_id=? ORDER BY FIELD(status,'selected','active','stale','dismissed'),provider_observed_at DESC,id ASC LIMIT 240");$q->execute([$tripId]);$rows=$q->fetchAll()?:[];foreach($rows as &$row){$row['transaction_required']=!empty($row['transaction_required']);$row['public_payload']=$this->publicPayload((string)($row['public_payload_json']??''));unset($row['public_payload_json']);}unset($row);return $rows;
    }

    private function bookings(int $tripId): array
    {
        if(!db_table_exists('trip_bookings'))return [];$q=$this->pdo->prepare("SELECT id,booking_type,title,provider_name,provider_url,status,operational_status,starts_at,ends_at,flight_number,departure_iata,arrival_iata FROM trip_bookings WHERE dream_trip_id=? AND status IN ('booked','confirmed','changed','ready_to_book') ORDER BY COALESCE(starts_at,'9999-12-31'),id");$q->execute([$tripId]);return $q->fetchAll()?:[];
    }

    private function sourceBooking(array $incident,array $bookings): ?array
    {
        if(($incident['source_type']??'')!=='booking')return null;$id=(int)($incident['source_key']??0);foreach($bookings as $b)if((int)$b['id']===$id)return $b;return null;
    }

    private function syncInbox(int $userId,int $tripId,array $incidents): void
    {
        if(!db_table_exists('trip_inbox_items'))return;$active=[];
        foreach($incidents as $i){if(!in_array((string)$i['status'],['open','reviewed'],true)||!in_array((string)$i['severity'],['high','critical'],true))continue;$key='incident:'.(int)$i['id'];$active[]=$key;$priority=$i['severity']==='critical'?100:98;$fingerprint=hash('sha256',(string)$i['incident_key'].'|'.(string)$i['severity'].'|'.(string)$i['title'].'|'.(string)$i['summary']);$sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,occurred_at) VALUES (?,?,?,?,?,'risk',?,'now',?,?,?,?,'open',1,?,NOW()) ON DUPLICATE KEY UPDATE priority=VALUES(priority),urgency=VALUES(urgency),title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status='open',requires_action=1,read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,resolved_at),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";$this->pdo->prepare($sql)->execute([$userId,$tripId,'recovery_intelligence',$key,'recovery:'.$tripId,$priority,(string)$i['title'],(string)$i['summary'],'Open Recovery Center',app_url('trip-recovery.php?id='.$tripId),$fingerprint]);}
        $params=[$userId,$tripId,'recovery_intelligence'];$sql="UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),source_status='resolved',updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND source_type=? AND resolved_at IS NULL";if($active){$sql.=' AND source_key NOT IN ('.implode(',',array_fill(0,count($active),'?')).')';$params=array_merge($params,$active);}$this->pdo->prepare($sql)->execute($params);
    }

    private function event(int $tripId,?int $incidentId,?int $optionId,?int $userId,string $type,array $payload): void
    {
        if(!db_table_exists('trip_recovery_events'))return;$json=$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE):null;$this->pdo->prepare('INSERT INTO trip_recovery_events (dream_trip_id,incident_id,option_id,user_id,event_type,event_json) VALUES (?,?,?,?,?,?)')->execute([$tripId,$incidentId,$optionId,$userId,$type,$json]);
    }

    private function trip(int $tripId): array
    {
        $q=$this->pdo->prepare('SELECT * FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);$trip=$q->fetch();if(!$trip)throw new OutOfBoundsException('Trip not found.');return $trip;
    }

    private function publicTrip(array $trip): array{return ['id'=>(int)$trip['id'],'name'=>(string)$trip['name'],'destination_name'=>(string)($trip['destination_name']??''),'start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'operational_state'=>(string)($trip['operational_state']??'planning')];}
    private function emptySnapshot(array $trip,array $access): array{return ['ready'=>false,'trip'=>$this->publicTrip($trip),'role'=>$access['role']??'viewer','can_plan'=>!empty($access['can_plan']),'can_prepare'=>!empty($access['is_owner']),'incidents'=>[],'summary'=>['open'=>0,'critical'=>0,'high'=>0,'options'=>0,'provider_options'=>0],'safety_note'=>'Run System Upgrade for Recovery & Rebooking Intelligence.'];}
    private function publicPayload(string $json): array{$v=$json!==''?json_decode($json,true):null;return is_array($v)?$v:[];}
    private function externalUrl(string $url): ?string{$url=trim($url);if($url===''||strlen($url)>1500||!preg_match('#^https://#i',$url))return null;$host=(string)(parse_url($url,PHP_URL_HOST)?:'');return $host!==''?$url:null;}
    private function providerTime(string $iso): ?string{$ts=$iso!==''?strtotime($iso):false;return $ts?date('Y-m-d H:i:s',$ts):date('Y-m-d H:i:s');}
    private function dateTimeOrNull(?string $v): ?string{$v=trim((string)$v);if($v==='')return null;$ts=strtotime($v);return $ts?date('Y-m-d H:i:s',$ts):null;}
    private function clip(string $v,int $max): string{$v=trim(preg_replace('/\s+/u',' ',$v)??$v);return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
    private function nullableClip(?string $v,int $max): ?string{$s=$this->clip((string)$v,$max);return $s===''?null:$s;}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Recovery & Rebooking Intelligence.');}
}
