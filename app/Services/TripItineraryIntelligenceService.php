<?php
declare(strict_types=1);

/**
 * Derived itinerary intelligence over canonical dream_trip_items + trip_bookings.
 *
 * This service may calculate, rank and persist normalized conflict state, but it
 * never books, cancels, purchases, calls a provider, or silently changes a fixed
 * reservation. Flexible-item ordering is applied only through an explicit user action.
 */
final class TripItineraryIntelligenceService
{
    private const TIMING_MODES=['fixed','flexible','anytime'];
    private const DAYPART_TIMES=['morning'=>'09:00:00','afternoon'=>'14:00:00','evening'=>'19:00:00','anytime'=>'12:00:00'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_itinerary_preferences')
            && db_table_exists('trip_itinerary_issues')
            && db_column_exists('dream_trips','itinerary_analyzed_at')
            && db_column_exists('dream_trip_items','starts_at')
            && db_column_exists('dream_trip_items','timing_mode');
    }

    public function access(int $userId,int $tripId): array
    {
        if($userId<1||$tripId<1)throw new OutOfBoundsException('Trip not found.');
        if(class_exists('TripCollaborationService')){
            $access=(new TripCollaborationService($this->pdo))->access($userId,$tripId);
            if($access&&!empty($access['can_view']))return $access;
        }
        $q=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);
        $owner=(int)($q->fetchColumn()?:0);if($owner===$userId)return ['role'=>'owner','is_owner'=>true,'can_view'=>true,'can_plan'=>true,'can_view_bookings'=>true];
        throw new OutOfBoundsException('Trip not found or permission denied.');
    }

    public function snapshot(int $userId,int $tripId,?string $selectedDate=null,bool $sync=true): array
    {
        $access=$this->access($userId,$tripId);$trip=$this->trip($tripId);$prefs=$this->preferences($tripId);
        if(!$this->ready())return ['ready'=>false,'trip'=>$this->publicTrip($trip),'access'=>$access,'selected_date'=>$this->selectedDate($selectedDate,$trip),'dates'=>[],'timeline'=>[],'days'=>[],'issues'=>[],'gaps'=>[],'unscheduled'=>[],'summary'=>$this->emptySummary(),'preferences'=>$prefs,'map_points'=>[]];

        $entries=$this->entries($trip,$prefs,!empty($access['can_view_bookings']));
        $unscheduled=array_values(array_filter($entries,fn(array $e)=>empty($e['date'])));
        $scheduled=array_values(array_filter($entries,fn(array $e)=>!empty($e['date'])));
        $days=[];foreach($scheduled as $entry)$days[(string)$entry['date']][]=$entry;
        ksort($days);

        $allIssues=[];$allGaps=[];$mapPoints=[];$normalizedDays=[];
        foreach($days as $date=>$dayEntries){
            $timeline=$this->sequenceDay($dayEntries,$prefs,$date);
            [$issues,$gaps]=$this->analyzeDay($trip,$timeline,$date);
            $allIssues=array_merge($allIssues,$issues);$allGaps=array_merge($allGaps,$gaps);$normalizedDays[$date]=$timeline;
            foreach($timeline as $entry){if($entry['latitude']!==null&&$entry['longitude']!==null)$mapPoints[$entry['key']]=['key'=>$entry['key'],'title'=>$entry['title'],'latitude'=>$entry['latitude'],'longitude'=>$entry['longitude'],'location'=>$entry['location']];}
        }
        if($unscheduled){$allIssues[]=$this->issue('unscheduled-items','planning_gap','low',null,null,null,'Some itinerary ideas still need a day',count($unscheduled).' saved itinerary item'.(count($unscheduled)===1?' is':'s are').' not assigned to a trip day yet.',['count'=>count($unscheduled)]);}
        $allIssues=array_merge($allIssues,$this->tripBoundaryIssues($trip,$scheduled));
        $selected=$this->selectedDate($selectedDate,$trip,array_keys($normalizedDays));
        $timeline=$normalizedDays[$selected]??[];$selectedIssues=array_values(array_filter($allIssues,fn(array $i)=>$i['date']===null||$i['date']===$selected));$selectedGaps=array_values(array_filter($allGaps,fn(array $g)=>$g['date']===$selected));
        if($sync&&!empty($access['can_plan']))$this->syncDerivedState($tripId,$allIssues);
        $summary=$this->summary($scheduled,$allIssues,$allGaps,$normalizedDays);
        return ['ready'=>true,'generated_at'=>date(DATE_ATOM),'trip'=>$this->publicTrip($trip),'access'=>$access,'selected_date'=>$selected,'dates'=>$this->dateRange($trip,array_keys($normalizedDays)),'timeline'=>$timeline,'days'=>$normalizedDays,'issues'=>$selectedIssues,'all_issues'=>$allIssues,'gaps'=>$selectedGaps,'all_gaps'=>$allGaps,'unscheduled'=>$unscheduled,'summary'=>$summary,'preferences'=>$prefs,'map_points'=>array_values($mapPoints)];
    }

    public function preferences(int $tripId): array
    {
        $defaults=['airport_domestic_minutes'=>120,'airport_international_minutes'=>180,'station_buffer_minutes'=>45,'venue_buffer_minutes'=>30,'default_transfer_minutes'=>30,'hotel_checkin_hour'=>15,'hotel_checkout_hour'=>11];
        if(!$this->ready())return $defaults;$q=$this->pdo->prepare('SELECT airport_domestic_minutes,airport_international_minutes,station_buffer_minutes,venue_buffer_minutes,default_transfer_minutes,hotel_checkin_hour,hotel_checkout_hour FROM trip_itinerary_preferences WHERE dream_trip_id=?');$q->execute([$tripId]);$row=$q->fetch();return $row?array_merge($defaults,$row):$defaults;
    }

    public function savePreferences(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$this->requirePlanningAccess($userId,$tripId);
        $v=[
            'airport_domestic_minutes'=>$this->clampInt($input['airport_domestic_minutes']??120,30,360),
            'airport_international_minutes'=>$this->clampInt($input['airport_international_minutes']??180,60,480),
            'station_buffer_minutes'=>$this->clampInt($input['station_buffer_minutes']??45,0,180),
            'venue_buffer_minutes'=>$this->clampInt($input['venue_buffer_minutes']??30,0,180),
            'default_transfer_minutes'=>$this->clampInt($input['default_transfer_minutes']??30,0,240),
            'hotel_checkin_hour'=>$this->clampInt($input['hotel_checkin_hour']??15,0,23),
            'hotel_checkout_hour'=>$this->clampInt($input['hotel_checkout_hour']??11,0,23),
        ];
        $sql='INSERT INTO trip_itinerary_preferences (dream_trip_id,airport_domestic_minutes,airport_international_minutes,station_buffer_minutes,venue_buffer_minutes,default_transfer_minutes,hotel_checkin_hour,hotel_checkout_hour,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE airport_domestic_minutes=VALUES(airport_domestic_minutes),airport_international_minutes=VALUES(airport_international_minutes),station_buffer_minutes=VALUES(station_buffer_minutes),venue_buffer_minutes=VALUES(venue_buffer_minutes),default_transfer_minutes=VALUES(default_transfer_minutes),hotel_checkin_hour=VALUES(hotel_checkin_hour),hotel_checkout_hour=VALUES(hotel_checkout_hour),updated_by=VALUES(updated_by),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$tripId,$v['airport_domestic_minutes'],$v['airport_international_minutes'],$v['station_buffer_minutes'],$v['venue_buffer_minutes'],$v['default_transfer_minutes'],$v['hotel_checkin_hour'],$v['hotel_checkout_hour'],$userId]);
        $this->snapshot($userId,$tripId,null,true);return $this->preferences($tripId);
    }

    public function saveItemTiming(int $userId,int $tripId,int $itemId,array $input): void
    {
        $this->requireReady();$this->requirePlanningAccess($userId,$tripId);$q=$this->pdo->prepare('SELECT id,scheduled_date,daypart,duration_minutes,timing_mode FROM dream_trip_items WHERE id=? AND dream_trip_id=? LIMIT 1');$q->execute([$itemId,$tripId]);$current=$q->fetch();if(!$current)throw new OutOfBoundsException('Itinerary item not found.');
        $date=$this->dateOrNull((string)($input['scheduled_date']??$current['scheduled_date']??''));$daypart=$this->daypart((string)($input['daypart']??$current['daypart']??'anytime'));
        $mode=strtolower(trim((string)($input['timing_mode']??$current['timing_mode']??'flexible')));if(!in_array($mode,self::TIMING_MODES,true))$mode='flexible';
        $startTime=$this->timeOrNull((string)($input['start_time']??''));$endTime=$this->timeOrNull((string)($input['end_time']??''));$starts=$date&&$startTime?$date.' '.$startTime:null;$ends=$date&&$endTime?$date.' '.$endTime:null;
        $duration=$this->nullableInt($input['duration_minutes']??$current['duration_minutes']??null,15,1440);if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new InvalidArgumentException('End time must be after start time on the same itinerary day.');if($starts&&!$ends&&$duration)$ends=date('Y-m-d H:i:s',strtotime($starts)+$duration*60);
        $location=$this->nullableClip((string)($input['location_name']??''),180);$address=$this->nullableClip((string)($input['location_address']??''),300);$lat=$this->coordinate($input['latitude']??null,-90,90);$lng=$this->coordinate($input['longitude']??null,-180,180);if(($lat===null)!==($lng===null)){ $lat=null;$lng=null; }
        $before=$this->nullableInt($input['buffer_before_minutes']??null,0,480);$after=$this->nullableInt($input['buffer_after_minutes']??null,0,480);
        $stmt=$this->pdo->prepare('UPDATE dream_trip_items SET scheduled_date=?,daypart=?,starts_at=?,ends_at=?,duration_minutes=?,timing_mode=?,location_name=?,location_address=?,latitude=?,longitude=?,buffer_before_minutes=?,buffer_after_minutes=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?');
        $stmt->execute([$date,$daypart,$starts,$ends,$duration,$mode,$location,$address,$lat,$lng,$before,$after,$itemId,$tripId]);$this->snapshot($userId,$tripId,$date,true);
    }

    public function applySuggestedOrder(int $userId,int $tripId,string $date): int
    {
        $this->requireReady();$this->requirePlanningAccess($userId,$tripId);$date=$this->dateOrNull($date)??throw new InvalidArgumentException('Choose a valid itinerary day.');$snapshot=$this->snapshot($userId,$tripId,$date,false);$items=[];foreach($snapshot['timeline'] as $entry){if(($entry['source']??'')==='itinerary'&&!empty($entry['item_id']))$items[]=(int)$entry['item_id'];}if(!$items)return 0;
        $this->pdo->beginTransaction();try{$order=10;foreach($items as $itemId){$this->pdo->prepare('UPDATE dream_trip_items SET sort_order=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?')->execute([$order,$itemId,$tripId]);$order+=10;}$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->snapshot($userId,$tripId,$date,true);return count($items);
    }

    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limitTrips=max(1,min(5,$limitTrips));$tripIds=[];
        $q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY COALESCE(start_date,'9999-12-31'),id LIMIT {$limitTrips}");$q->execute([$userId]);$tripIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if(count($tripIds)<$limitTrips&&db_table_exists('trip_collaborators')){$remaining=$limitTrips-count($tripIds);$s=$this->pdo->prepare("SELECT dream_trip_id FROM trip_collaborators tc JOIN dream_trips dt ON dt.id=tc.dream_trip_id WHERE tc.user_id=? AND tc.status='active' AND dt.status<>'abandoned' AND (dt.end_date IS NULL OR dt.end_date>=CURDATE()) ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.id LIMIT {$remaining}");$s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$tripIds[]=(int)$id;}
        $out=[];foreach(array_values(array_unique($tripIds)) as $tripId){try{$snap=$this->snapshot($userId,$tripId,null,false);}catch(Throwable){continue;}$next=array_values(array_filter($snap['timeline'],fn(array $e)=>in_array($e['phase'],['now','next','later'],true)));$out[]=['trip_id'=>$tripId,'trip_name'=>$snap['trip']['name'],'selected_date'=>$snap['selected_date'],'role'=>$snap['access']['role'],'high_issues'=>(int)$snap['summary']['high_issues'],'medium_issues'=>(int)$snap['summary']['medium_issues'],'next'=>array_slice(array_map(fn(array $e)=>['title'=>$e['title'],'time'=>$e['time_label'],'type'=>$e['type'],'leave_by'=>$e['leave_by'],'location'=>$e['location']],$next),0,4)];}
        return $out;
    }

    public function runUpcoming(int $limit=25): array
    {
        if(!$this->ready())return ['checked'=>0,'issues'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));$rows=$this->pdo->query("SELECT id,user_id FROM dream_trips WHERE status<>'abandoned' AND (end_date IS NULL OR end_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) AND ((start_date IS NOT NULL AND start_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)) OR operational_state IN ('ready','traveling')) ORDER BY COALESCE(itinerary_analyzed_at,'1970-01-01 00:00:00'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limit}")->fetchAll()?:[];$out=['checked'=>0,'issues'=>0,'errors'=>0];foreach($rows as $row){$out['checked']++;try{$snap=$this->snapshot((int)$row['user_id'],(int)$row['id'],null,true);$out['issues']+=count($snap['all_issues']??[]);}catch(Throwable $e){$out['errors']++;error_log('Itinerary intelligence sync failed: '.$e->getMessage());}}return $out;
    }

    private function trip(int $tripId): array
    {
        $q=$this->pdo->prepare('SELECT dt.*,u.country_code traveler_country_code,dc.country_code destination_country_code FROM dream_trips dt JOIN users u ON u.id=dt.user_id LEFT JOIN destination_catalog dc ON dc.id=dt.destination_catalog_id WHERE dt.id=? LIMIT 1');$q->execute([$tripId]);$trip=$q->fetch();if(!$trip)throw new OutOfBoundsException('Trip not found.');$meta=json_decode((string)($trip['metadata_json']??''),true)?:[];$trip['destination_name']=(string)($meta['destination_name']??'');return $trip;
    }

    private function publicTrip(array $trip): array
    {
        return ['id'=>(int)$trip['id'],'owner_user_id'=>(int)$trip['user_id'],'name'=>(string)$trip['name'],'destination_name'=>(string)$trip['destination_name'],'start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'operational_state'=>(string)($trip['operational_state']??'planning'),'itinerary_analyzed_at'=>$trip['itinerary_analyzed_at']??null];
    }

    private function entries(array $trip,array $prefs,bool $canViewBookings): array
    {
        $tripId=(int)$trip['id'];$entries=[];
        if($canViewBookings&&db_table_exists('trip_bookings')){$q=$this->pdo->prepare("SELECT id,booking_type,title,provider_name,status,starts_at,ends_at,location_name,location_address,terminal,gate,operational_status,status_source FROM trip_bookings WHERE dream_trip_id=? AND status IN ('booked','confirmed','changed') ORDER BY starts_at IS NULL,starts_at,id");$q->execute([$tripId]);foreach($q->fetchAll()?:[] as $b){$type=(string)$b['booking_type'];if($type==='lodging'){$entries=array_merge($entries,$this->lodgingEntries($b,$trip,$prefs));continue;}if(empty($b['starts_at']))continue;$start=strtotime((string)$b['starts_at']);if(!$start)continue;$end=!empty($b['ends_at'])&&strtotime((string)$b['ends_at'])?strtotime((string)$b['ends_at']):$start+$this->defaultDuration($type)*60;$entries[]=$this->entry('booking:'.(int)$b['id'],'booking',(int)$b['id'],null,$type,(string)$b['title'],$start,$end,'fixed',(string)($b['location_name']??''),(string)($b['location_address']??''),null,null,$this->bookingBufferBefore($type,$trip,$prefs),$this->bookingBufferAfter($type),(string)($b['provider_name']??''),(string)($b['operational_status']??'unknown'),(string)($b['status_source']??''),0);}}
        $q=$this->pdo->prepare('SELECT id,item_type,title,scheduled_date,daypart,starts_at,ends_at,duration_minutes,timing_mode,location_name,location_address,latitude,longitude,buffer_before_minutes,buffer_after_minutes,source_provider,sort_order FROM dream_trip_items WHERE dream_trip_id=? ORDER BY scheduled_date IS NULL,scheduled_date,sort_order,id');$q->execute([$tripId]);foreach($q->fetchAll()?:[] as $i){$date=$this->dateOrNull((string)($i['scheduled_date']??''));$exactStart=!empty($i['starts_at'])&&strtotime((string)$i['starts_at'])?strtotime((string)$i['starts_at']):null;$start=$exactStart;if(!$start&&$date){$daypart=$this->daypart((string)($i['daypart']??'anytime'));$start=strtotime($date.' '.self::DAYPART_TIMES[$daypart]);}$duration=(int)($i['duration_minutes']??0);if($duration<1)$duration=$this->defaultDuration((string)$i['item_type']);$end=!empty($i['ends_at'])&&strtotime((string)$i['ends_at'])?strtotime((string)$i['ends_at']):($start?$start+$duration*60:null);$mode=(string)($i['timing_mode']??'flexible');if(!in_array($mode,self::TIMING_MODES,true))$mode=$exactStart?'fixed':'flexible';$entries[]=$this->entry('item:'.(int)$i['id'],'itinerary',null,(int)$i['id'],(string)$i['item_type'],(string)$i['title'],$start,$end,$mode,(string)($i['location_name']??''),(string)($i['location_address']??''),$this->floatOrNull($i['latitude']??null),$this->floatOrNull($i['longitude']??null),$i['buffer_before_minutes']!==null?(int)$i['buffer_before_minutes']:$this->itemBufferBefore((string)$i['item_type'],$prefs),$i['buffer_after_minutes']!==null?(int)$i['buffer_after_minutes']:10,(string)($i['source_provider']??''),'unknown','',(int)$i['sort_order'],$date,(string)($i['daypart']??'anytime'));}
        return $entries;
    }

    private function lodgingEntries(array $b,array $trip,array $prefs): array
    {
        $startDate=!empty($b['starts_at'])?substr((string)$b['starts_at'],0,10):null;$endDate=!empty($b['ends_at'])?substr((string)$b['ends_at'],0,10):null;if(!$startDate)return [];$rows=[];$checkin=strtotime($startDate.' '.sprintf('%02d:00:00',(int)$prefs['hotel_checkin_hour']));$rows[]=$this->entry('booking:'.(int)$b['id'].':checkin','booking',(int)$b['id'],null,'lodging',(string)$b['title'].' · Check-in',$checkin,$checkin+30*60,'fixed',(string)($b['location_name']??''),(string)($b['location_address']??''),null,null,30,0,(string)($b['provider_name']??''),(string)($b['operational_status']??'unknown'),(string)($b['status_source']??''),0);if($endDate&&$endDate!==$startDate){$checkout=strtotime($endDate.' '.sprintf('%02d:00:00',(int)$prefs['hotel_checkout_hour']));$rows[]=$this->entry('booking:'.(int)$b['id'].':checkout','booking',(int)$b['id'],null,'lodging',(string)$b['title'].' · Check-out',$checkout,$checkout+15*60,'fixed',(string)($b['location_name']??''),(string)($b['location_address']??''),null,null,0,0,(string)($b['provider_name']??''),(string)($b['operational_status']??'unknown'),(string)($b['status_source']??''),1);}return $rows;
    }

    private function entry(string $key,string $source,?int $bookingId,?int $itemId,string $type,string $title,?int $start,?int $end,string $mode,string $location,string $address,?float $lat,?float $lng,int $before,int $after,string $provider,string $opsStatus,string $statusSource,int $sort,?string $fallbackDate=null,string $daypart='anytime'): array
    {
        $date=$start?date('Y-m-d',$start):$fallbackDate;return ['key'=>$key,'source'=>$source,'booking_id'=>$bookingId,'item_id'=>$itemId,'type'=>$type,'title'=>$title,'start_ts'=>$start,'end_ts'=>$end,'starts_at'=>$start?date('Y-m-d H:i:s',$start):null,'ends_at'=>$end?date('Y-m-d H:i:s',$end):null,'date'=>$date,'daypart'=>$this->daypart($daypart),'timing_mode'=>$mode,'fixed'=>$source==='booking'||$mode==='fixed','location'=>trim($location),'address'=>trim($address),'latitude'=>$lat,'longitude'=>$lng,'buffer_before'=>$before,'buffer_after'=>$after,'provider'=>$provider,'operational_status'=>$opsStatus,'status_source'=>$statusSource,'sort_order'=>$sort,'suggested_sort'=>$sort,'transition'=>null,'leave_by'=>null,'arrive_by'=>null,'time_label'=>$start?date('g:i A',$start):'Time TBD','phase'=>'later','url'=>''];
    }

    private function sequenceDay(array $entries,array $prefs,string $date): array
    {
        usort($entries,fn($a,$b)=>(($a['start_ts']??PHP_INT_MAX)<=>($b['start_ts']??PHP_INT_MAX)) ?: (($a['sort_order']??0)<=>($b['sort_order']??0)));
        $out=[];$group=[];$flush=function()use(&$group,&$out){if(!$group)return;$ordered=$this->geoOrder($group,$out?end($out):null);foreach($ordered as $e)$out[]=$e;$group=[];};
        foreach($entries as $entry){if(!$entry['fixed']&&$entry['source']==='itinerary'){$group[]=$entry;}else{$flush();$out[]=$entry;}}$flush();
        $now=time();$today=date('Y-m-d');$nextMarked=false;$sort=10;
        foreach($out as $idx=>&$entry){$entry['suggested_sort']=$sort;$sort+=10;if($idx>0){$prev=$out[$idx-1];$transition=$this->transition($prev,$entry,$prefs);$entry['transition']=$transition;if($entry['start_ts']){$arrive=$entry['start_ts']-$entry['buffer_before']*60;$leave=$arrive-$transition['minutes']*60;$entry['arrive_by']=date('Y-m-d H:i:s',$arrive);$entry['leave_by']=date('Y-m-d H:i:s',$leave);}}elseif($entry['start_ts']&&$entry['buffer_before']>0){$entry['arrive_by']=date('Y-m-d H:i:s',$entry['start_ts']-$entry['buffer_before']*60);}
            $phase='later';if($date===$today&&$entry['start_ts']){if($entry['start_ts']<=$now&&($entry['end_ts']??$entry['start_ts'])>=$now)$phase='now';elseif($entry['start_ts']>$now&&!$nextMarked){$phase='next';$nextMarked=true;}elseif($entry['start_ts']>$now)$phase='later';else$phase='earlier';}$entry['phase']=$phase;$entry['url']=$entry['source']==='booking'?app_url('trip-bookings.php?id='.($this->tripIdFromEntryContext??0)):'';}
        unset($entry);return $out;
    }

    private function geoOrder(array $group,?array $previous): array
    {
        if(count($group)<2)return $group;$withGeo=array_values(array_filter($group,fn($e)=>$e['latitude']!==null&&$e['longitude']!==null));if(count($withGeo)<2)return $group;$remaining=$group;$ordered=[];$cursor=$previous&&$previous['latitude']!==null&&$previous['longitude']!==null?$previous:null;
        while($remaining){$best=0;$bestDistance=INF;if($cursor){foreach($remaining as $idx=>$candidate){if($candidate['latitude']===null||$candidate['longitude']===null)continue;$d=$this->distanceMiles((float)$cursor['latitude'],(float)$cursor['longitude'],(float)$candidate['latitude'],(float)$candidate['longitude']);if($d<$bestDistance){$bestDistance=$d;$best=$idx;}}}$next=$remaining[$best];array_splice($remaining,$best,1);$ordered[]=$next;if($next['latitude']!==null&&$next['longitude']!==null)$cursor=$next;}
        return $ordered;
    }

    private function analyzeDay(array $trip,array $timeline,string $date): array
    {
        $issues=[];$gaps=[];for($i=1;$i<count($timeline);$i++){$prev=$timeline[$i-1];$next=$timeline[$i];if(!$prev['end_ts']||!$next['start_ts'])continue;$transition=$next['transition']??['minutes'=>0,'label'=>'transfer'];$prevAvailable=$prev['end_ts']+$prev['buffer_after']*60;$arriveBy=$next['start_ts']-$next['buffer_before']*60;$leaveBy=$arriveBy-(int)$transition['minutes']*60;
            if($prevAvailable>$next['start_ts']){$minutes=(int)ceil(($prevAvailable-$next['start_ts'])/60);$key='overlap:'.$prev['key'].':'.$next['key'];$issues[]=$this->issue($key,'schedule_conflict','high',$date,$prev['key'],$next['key'],'Schedule overlap: '.$prev['title'].' → '.$next['title'],$prev['title'].' runs about '.$minutes.' minutes into '.$next['title'].'. Move a flexible item or change the plan before relying on this sequence.',['minutes'=>$minutes]);}
            elseif($prevAvailable>$leaveBy){$short=(int)ceil(($prevAvailable-$leaveBy)/60);$severity=$short>=30?'high':'medium';$key='transfer:'.$prev['key'].':'.$next['key'];$issues[]=$this->issue($key,'transfer_risk',$severity,$date,$prev['key'],$next['key'],'Not enough transfer time before '.$next['title'],'The current sequence is short by about '.$short.' minutes after travel and preparation buffers. Vacation Brain estimates '.$transition['minutes'].' minutes of transfer time.',['shortfall_minutes'=>$short,'transfer_minutes'=>$transition['minutes']]);}
            else{$free=(int)floor(($leaveBy-$prevAvailable)/60);if($free>=150)$gaps[]=['key'=>'gap:'.$prev['key'].':'.$next['key'],'date'=>$date,'start_at'=>date('Y-m-d H:i:s',$prevAvailable),'end_at'=>date('Y-m-d H:i:s',$leaveBy),'minutes'=>$free,'title'=>$free>=300?'Large open block':'Open time','body'=>$free.' minutes are available between '.$prev['title'].' and the leave-by time for '.$next['title'].'.'];}
        }
        $scheduledMinutes=0;foreach($timeline as $entry)if($entry['start_ts']&&$entry['end_ts'])$scheduledMinutes+=max(0,(int)(($entry['end_ts']-$entry['start_ts'])/60));if($scheduledMinutes>720)$issues[]=$this->issue('overloaded:'.$date,'overloaded_day','low',$date,null,null,'This day is heavily scheduled','Vacation Brain sees about '.round($scheduledMinutes/60,1).' hours of scheduled activity before transfer buffers. Consider protecting some unscheduled time.',['scheduled_minutes'=>$scheduledMinutes]);
        return [$issues,$gaps];
    }

    private function tripBoundaryIssues(array $trip,array $entries): array
    {
        $issues=[];$start=(string)($trip['start_date']??'');$end=(string)($trip['end_date']??'');foreach($entries as $entry){$date=(string)($entry['date']??'');if($date==='')continue;if($start!==''&&$date<$start)$issues[]=$this->issue('before-trip:'.$entry['key'],'trip_boundary','medium',$date,$entry['key'],null,$entry['title'].' is before the trip starts','This itinerary item is scheduled for '.$date.', before the trip begins on '.$start.'.',[]);if($end!==''&&$date>$end)$issues[]=$this->issue('after-trip:'.$entry['key'],'trip_boundary','medium',$date,$entry['key'],null,$entry['title'].' is after the trip ends','This itinerary item is scheduled for '.$date.', after the trip ends on '.$end.'.',[]);}return $issues;
    }

    private function issue(string $key,string $type,string $severity,?string $date,?string $a,?string $b,string $title,string $body,array $meta): array
    {
        return ['key'=>$this->clip($key,190),'type'=>$type,'severity'=>$severity,'date'=>$date,'source_a_key'=>$a,'source_b_key'=>$b,'title'=>$this->clip($title,180),'body'=>$this->clip($body,700),'metadata'=>$meta];
    }

    private function syncDerivedState(int $tripId,array $issues): void
    {
        $this->pdo->beginTransaction();try{$seen=[];foreach($issues as $issue){$seen[]=$issue['key'];$sql='INSERT INTO trip_itinerary_issues (dream_trip_id,issue_key,issue_type,severity,itinerary_date,source_a_key,source_b_key,title,body,metadata_json,first_detected_at,last_seen_at,resolved_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NULL) ON DUPLICATE KEY UPDATE issue_type=VALUES(issue_type),severity=VALUES(severity),itinerary_date=VALUES(itinerary_date),source_a_key=VALUES(source_a_key),source_b_key=VALUES(source_b_key),title=VALUES(title),body=VALUES(body),metadata_json=VALUES(metadata_json),last_seen_at=NOW(),resolved_at=NULL,updated_at=NOW()';$this->pdo->prepare($sql)->execute([$tripId,$issue['key'],$issue['type'],$issue['severity'],$issue['date'],$issue['source_a_key'],$issue['source_b_key'],$issue['title'],$issue['body'],json_encode($issue['metadata'],JSON_UNESCAPED_SLASHES)]);}
            $params=[$tripId];$sql='UPDATE trip_itinerary_issues SET resolved_at=COALESCE(resolved_at,NOW()),updated_at=NOW() WHERE dream_trip_id=? AND resolved_at IS NULL';if($seen){$sql.=' AND issue_key NOT IN ('.implode(',',array_fill(0,count($seen),'?')).')';$params=array_merge($params,$seen);}$this->pdo->prepare($sql)->execute($params);$this->pdo->prepare('UPDATE dream_trips SET itinerary_analyzed_at=NOW() WHERE id=?')->execute([$tripId]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->syncTripInbox($tripId,$issues);$this->syncOperationSignals($tripId,$issues);
    }

    private function syncTripInbox(int $tripId,array $issues): void
    {
        if(!db_table_exists('trip_inbox_items'))return;$recipients=[];$q=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=?');$q->execute([$tripId]);$owner=(int)($q->fetchColumn()?:0);if($owner)$recipients[$owner]='owner';if(db_table_exists('trip_collaborators')){$c=$this->pdo->prepare("SELECT user_id,role FROM trip_collaborators WHERE dream_trip_id=? AND status='active' AND role IN ('co_planner','traveler')");$c->execute([$tripId]);foreach($c->fetchAll()?:[] as $row)$recipients[(int)$row['user_id']]=(string)$row['role'];}
        $active=[];foreach($issues as $issue){if($issue['severity']==='low')continue;$active[]=$issue['key'];foreach($recipients as $uid=>$role){if($role==='traveler'&&$issue['severity']!=='high')continue;$priority=$issue['severity']==='high'?98:82;$urgency=$issue['severity']==='high'?'now':'today';$fingerprint=hash('sha256',json_encode([$issue['severity'],$issue['title'],$issue['body'],$issue['date']],JSON_UNESCAPED_SLASHES));$sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,due_at,occurred_at) VALUES (?,?,?,?,?,'risk',?,?,?,?,?,?,?,1,?,NULL,NOW()) ON DUPLICATE KEY UPDATE dream_trip_id=VALUES(dream_trip_id),thread_key=VALUES(thread_key),priority=VALUES(priority),urgency=VALUES(urgency),title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status=VALUES(source_status),requires_action=1,read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,resolved_at),snoozed_until=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,snoozed_until),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";$this->pdo->prepare($sql)->execute([$uid,$tripId,'itinerary_issue',$issue['key'],'itinerary:'.($issue['date']?:'general'),$priority,$urgency,$issue['title'],$issue['body'],'Open timeline',app_url('trip-itinerary.php?id='.$tripId.($issue['date']?'&date='.$issue['date']:'')),$issue['severity'],$fingerprint]);}}
        foreach(array_keys($recipients) as $uid){$params=[$uid,$tripId,'itinerary_issue'];$sql='UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),source_status="resolved",updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND source_type=? AND resolved_at IS NULL';if($active){$sql.=' AND source_key NOT IN ('.implode(',',array_fill(0,count($active),'?')).')';$params=array_merge($params,$active);}$this->pdo->prepare($sql)->execute($params);}
    }

    private function syncOperationSignals(int $tripId,array $issues): void
    {
        if(!db_table_exists('trip_operation_events'))return;$ownerQ=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=?');$ownerQ->execute([$tripId]);$owner=(int)($ownerQ->fetchColumn()?:0);if(!$owner)return;foreach($issues as $issue){if($issue['severity']==='low')continue;$key='itinerary:'.$issue['key'];$sql="INSERT INTO trip_operation_events (user_id,dream_trip_id,booking_id,source_key,event_type,severity,title,body,metadata_json) VALUES (?,?,NULL,?,'itinerary_conflict',?,?,?,?,?) ON DUPLICATE KEY UPDATE severity=VALUES(severity),title=VALUES(title),body=VALUES(body),metadata_json=VALUES(metadata_json)";$this->pdo->prepare($sql)->execute([$owner,$tripId,$key,$issue['severity'],$issue['title'],$issue['body'],json_encode(['date'=>$issue['date'],'issue_key'=>$issue['key']],JSON_UNESCAPED_SLASHES)]);}
    }

    private function summary(array $scheduled,array $issues,array $gaps,array $days): array
    {
        $fixed=0;$flexible=0;foreach($scheduled as $e){if($e['fixed'])$fixed++;else$flexible++;}$high=count(array_filter($issues,fn($i)=>$i['severity']==='high'));$medium=count(array_filter($issues,fn($i)=>$i['severity']==='medium'));return ['scheduled'=>count($scheduled),'fixed'=>$fixed,'flexible'=>$flexible,'days'=>count($days),'high_issues'=>$high,'medium_issues'=>$medium,'low_issues'=>count($issues)-$high-$medium,'open_gaps'=>count($gaps)];
    }

    private function emptySummary(): array{return ['scheduled'=>0,'fixed'=>0,'flexible'=>0,'days'=>0,'high_issues'=>0,'medium_issues'=>0,'low_issues'=>0,'open_gaps'=>0];}

    private function transition(array $from,array $to,array $prefs): array
    {
        $fromLoc=strtolower(trim((string)($from['location']?:$from['address'])));$toLoc=strtolower(trim((string)($to['location']?:$to['address'])));if($fromLoc!==''&&$fromLoc===$toLoc)return ['minutes'=>0,'miles'=>0.0,'mode'=>'same_place','label'=>'Same location'];
        if($from['latitude']!==null&&$from['longitude']!==null&&$to['latitude']!==null&&$to['longitude']!==null){$miles=$this->distanceMiles((float)$from['latitude'],(float)$from['longitude'],(float)$to['latitude'],(float)$to['longitude']);if($miles<=1.5){$minutes=(int)ceil(($miles/3)*60+5);$mode='walk';}else{$minutes=(int)ceil(($miles/25)*60+10);$mode='ground';}return ['minutes'=>max(5,min(180,$minutes)),'miles'=>round($miles,1),'mode'=>$mode,'label'=>($mode==='walk'?'Walk':'Ground transfer').' · '.round($miles,1).' mi'];}
        $minutes=(int)$prefs['default_transfer_minutes'];return ['minutes'=>$minutes,'miles'=>null,'mode'=>'estimated','label'=>'Estimated transfer · '.$minutes.' min'];
    }

    private function bookingBufferBefore(string $type,array $trip,array $prefs): int
    {
        if($type==='flight'){$traveler=strtoupper((string)($trip['traveler_country_code']??''));$destination=strtoupper((string)($trip['destination_country_code']??''));$international=$traveler!==''&&$destination!==''&&$traveler!==$destination;return (int)($international?$prefs['airport_international_minutes']:$prefs['airport_domestic_minutes']);}if($type==='transport')return (int)$prefs['station_buffer_minutes'];if($type==='event')return (int)$prefs['venue_buffer_minutes'];return match($type){'restaurant'=>15,'activity'=>20,'lodging'=>30,default=>10};
    }
    private function bookingBufferAfter(string $type): int{return match($type){'flight'=>45,'transport'=>15,'event'=>15,'activity'=>10,default=>0};}
    private function itemBufferBefore(string $type,array $prefs): int{return match($type){'flight'=>(int)$prefs['airport_domestic_minutes'],'activity','experience'=>20,'food'=>15,'hotel'=>30,default=>10};}
    private function defaultDuration(string $type): int{return match($type){'flight'=>180,'lodging'=>30,'transport'=>60,'event'=>150,'restaurant','food'=>90,'activity','experience'=>120,'hotel'=>45,default=>60};}

    private function distanceMiles(float $lat1,float $lon1,float $lat2,float $lon2): float
    {
        $earth=3958.7613;$p1=deg2rad($lat1);$p2=deg2rad($lat2);$dlat=deg2rad($lat2-$lat1);$dlon=deg2rad($lon2-$lon1);$a=sin($dlat/2)**2+cos($p1)*cos($p2)*sin($dlon/2)**2;return $earth*2*atan2(sqrt($a),sqrt(max(0,1-$a)));
    }

    private function selectedDate(?string $date,array $trip,array $known=[]): string
    {
        $date=$this->dateOrNull((string)$date);if($date)return $date;$today=date('Y-m-d');$start=$this->dateOrNull((string)($trip['start_date']??''));$end=$this->dateOrNull((string)($trip['end_date']??''));if($start&&$end&&$today>=$start&&$today<=$end)return $today;if($start&&$start>=$today)return $start;if($known)return (string)$known[0];return $start?:$today;
    }

    private function dateRange(array $trip,array $known): array
    {
        $start=$this->dateOrNull((string)($trip['start_date']??''));$end=$this->dateOrNull((string)($trip['end_date']??''));$dates=[];if($start&&$end){$cursor=new DateTimeImmutable($start);$last=new DateTimeImmutable($end);$guard=0;while($cursor<=$last&&$guard<60){$dates[]=$cursor->format('Y-m-d');$cursor=$cursor->modify('+1 day');$guard++;}}foreach($known as $d)if(!in_array($d,$dates,true))$dates[]=$d;sort($dates);return $dates;
    }

    private function requirePlanningAccess(int $userId,int $tripId): array{$access=$this->access($userId,$tripId);if(empty($access['can_plan']))throw new DomainException('Only the trip owner or a Co-planner can change the shared itinerary.');return $access;}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Complete Itinerary Intelligence.');}
    private function clampInt(mixed $v,int $min,int $max): int{return max($min,min($max,(int)$v));}
    private function nullableInt(mixed $v,int $min,int $max): ?int{if($v===null||$v==='')return null;return $this->clampInt($v,$min,$max);}
    private function floatOrNull(mixed $v): ?float{return is_numeric($v)?(float)$v:null;}
    private function coordinate(mixed $v,float $min,float $max): ?float{if($v===null||$v==='')return null;if(!is_numeric($v))throw new InvalidArgumentException('Location coordinates must be numeric.');$n=(float)$v;if($n<$min||$n>$max)throw new InvalidArgumentException('Location coordinates are outside the valid range.');return $n;}
    private function nullableClip(string $v,int $limit): ?string{$v=$this->clip($v,$limit);return $v===''?null:$v;}
    private function clip(string $v,int $limit): string{$v=trim($v);return function_exists('mb_substr')?mb_substr($v,0,$limit):substr($v,0,$limit);}
    private function daypart(string $v): string{$v=strtolower(trim($v));return isset(self::DAYPART_TIMES[$v])?$v:'anytime';}
    private function dateOrNull(string $v): ?string{$v=trim($v);if($v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:null;}
    private function timeOrNull(string $v): ?string{$v=trim($v);if($v==='')return null;if(preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/',$v,$m)){if((int)$m[1]<24&&(int)$m[2]<60&&(empty($m[3])||(int)$m[3]<60))return sprintf('%02d:%02d:%02d',(int)$m[1],(int)$m[2],(int)($m[3]??0));}throw new InvalidArgumentException('Use a valid time.');}
}
