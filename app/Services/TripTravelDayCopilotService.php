<?php
declare(strict_types=1);

/**
 * Live, user-specific supervision over Complete Itinerary Intelligence.
 *
 * The Copilot derives focus, countdowns, ripples, checklists and briefings from
 * canonical saved trip facts. It may record traveler-declared progress and local
 * Copilot state, but it never books, cancels, purchases, refunds, checks out,
 * mutates a provider, scans a mailbox, or reads current-device coordinates.
 */
final class TripTravelDayCopilotService
{
    private const ITEM_STATES=['on_time','running_late','skipped','done','need_help'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return class_exists('TripItineraryIntelligenceService')
            && db_table_exists('trip_copilot_item_states')
            && db_table_exists('trip_copilot_checklist_items')
            && db_table_exists('trip_copilot_briefings')
            && db_column_exists('dream_trips','travel_copilot_checked_at');
    }

    public function snapshot(int $userId,int $tripId,?string $date=null,bool $sync=true): array
    {
        if($userId<1||$tripId<1)throw new InvalidArgumentException('Trip is required.');
        $itineraryService=new TripItineraryIntelligenceService($this->pdo);
        $access=$itineraryService->access($userId,$tripId);
        if(!$this->ready())return $this->emptySnapshot($tripId,$date,$access);

        $itinerary=$itineraryService->snapshot($userId,$tripId,$date,false);
        $selected=(string)$itinerary['selected_date'];
        $states=$this->states($userId,$tripId);
        $timeline=[];
        foreach((array)$itinerary['timeline'] as $entry){
            $key=(string)$entry['key'];$state=$states[$key]??null;
            $entry['traveler_state']=$state['traveler_state']??'on_time';
            $entry['delay_minutes']=(int)($state['delay_minutes']??0);
            $entry['traveler_observed_at']=$state['observed_at']??null;
            $timeline[]=$entry;
        }

        $focus=$this->focus($timeline,$selected);
        $ripples=$this->ripples($timeline,(array)$itinerary['issues'],$focus,$selected);
        if($sync){
            $this->ensureChecklist($userId,$tripId,$selected,$timeline,(array)$itinerary['trip']);
            $this->syncInbox($userId,$tripId,$ripples,$selected);
            $this->pdo->prepare('UPDATE dream_trips SET travel_copilot_checked_at=NOW() WHERE id=?')->execute([$tripId]);
        }
        $checklist=$this->checklist($userId,$tripId,$selected);
        $briefing=$this->briefing($userId,$tripId,$selected,$timeline,$focus,$ripples,$checklist,$sync);
        $next=$this->nextItems($timeline,$focus,4);
        $summary=[
            'open_checklist'=>count(array_filter($checklist,static fn(array $r):bool=>empty($r['completed_at']))),
            'required_open'=>count(array_filter($checklist,static fn(array $r):bool=>!empty($r['required'])&&empty($r['completed_at']))),
            'high_ripples'=>count(array_filter($ripples,static fn(array $r):bool=>$r['severity']==='high')),
            'medium_ripples'=>count(array_filter($ripples,static fn(array $r):bool=>$r['severity']==='medium')),
            'timeline_items'=>count($timeline),
        ];
        $snapshot=[
            'ready'=>true,'generated_at'=>date(DATE_ATOM),'trip_id'=>$tripId,'selected_date'=>$selected,
            'role'=>(string)($access['role']??'viewer'),'can_plan'=>!empty($access['can_plan']),
            'focus'=>$focus,'next'=>$next,'timeline'=>$timeline,'ripples'=>$ripples,
            'checklist'=>$checklist,'briefing'=>$briefing,'summary'=>$summary,
            'privacy_note'=>'Copilot uses saved trip facts and traveler-declared progress only. It does not refresh device location or expose confirmation/payment/private provider fields.',
        ];
        $snapshot['offline']=$this->offlineSnapshot($snapshot);
        return $snapshot;
    }

    public function setItemState(int $userId,int $tripId,string $itemKey,string $state,int $delayMinutes=0,?string $date=null): void
    {
        $this->requireReady();$state=strtolower(trim($state));
        if(!in_array($state,self::ITEM_STATES,true))throw new InvalidArgumentException('Unknown traveler status.');
        $itemKey=trim($itemKey);if($itemKey===''||strlen($itemKey)>190)throw new InvalidArgumentException('Choose a valid itinerary item.');
        $itinerary=(new TripItineraryIntelligenceService($this->pdo))->snapshot($userId,$tripId,$date,false);
        $found=false;foreach((array)$itinerary['timeline'] as $entry){if((string)$entry['key']===$itemKey){$found=true;break;}}
        if(!$found)throw new OutOfBoundsException('Itinerary item not found for this trip day.');
        $delay=$state==='running_late'?max(5,min(360,$delayMinutes?:15)):0;
        $sql='INSERT INTO trip_copilot_item_states (dream_trip_id,user_id,item_key,traveler_state,delay_minutes,observed_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE traveler_state=VALUES(traveler_state),delay_minutes=VALUES(delay_minutes),observed_at=NOW(),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$tripId,$userId,$itemKey,$state,$delay]);
        if($state==='need_help')$this->upsertInboxItem($userId,$tripId,'help:'.$itemKey,'high','now','Need help with the current travel step','You marked this travel step as needing help. Open Travel Mode or ask Vacation Brain to review the saved itinerary and available recovery options.',true,(string)$itinerary['selected_date']);
        $this->snapshot($userId,$tripId,(string)$itinerary['selected_date'],true);
    }

    public function setChecklistCompleted(int $userId,int $tripId,int $checklistId,bool $completed): void
    {
        $this->requireReady();(new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);
        $q=$this->pdo->prepare('SELECT id FROM trip_copilot_checklist_items WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');
        $q->execute([$checklistId,$userId,$tripId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Checklist item not found.');
        $this->pdo->prepare('UPDATE trip_copilot_checklist_items SET completed_at=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?')->execute([$completed?date('Y-m-d H:i:s'):null,$checklistId,$userId,$tripId]);
    }

    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limitTrips=max(1,min(5,$limitTrips));$ids=[];
        $q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' AND (end_date IS NULL OR end_date>=CURDATE()) ORDER BY FIELD(operational_state,'traveling','ready','booking','planning','completed'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limitTrips}");
        $q->execute([$userId]);foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ids[]=(int)$id;
        if(count($ids)<$limitTrips&&db_table_exists('trip_collaborators')){
            $remain=$limitTrips-count($ids);$s=$this->pdo->prepare("SELECT tc.dream_trip_id FROM trip_collaborators tc JOIN dream_trips dt ON dt.id=tc.dream_trip_id WHERE tc.user_id=? AND tc.status='active' AND dt.status<>'abandoned' AND (dt.end_date IS NULL OR dt.end_date>=CURDATE()) ORDER BY FIELD(dt.operational_state,'traveling','ready','booking','planning','completed'),COALESCE(dt.start_date,'9999-12-31'),dt.id LIMIT {$remain}");
            $s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ids[]=(int)$id;
        }
        $out=[];foreach(array_values(array_unique($ids)) as $tripId){
            try{$snap=$this->snapshot($userId,$tripId,null,false);}catch(Throwable){continue;}
            $focus=$snap['focus'];$out[]=[
                'trip_id'=>$tripId,'date'=>$snap['selected_date'],'role'=>$snap['role'],
                'focus'=>$focus?['title'=>$focus['title'],'type'=>$focus['type'],'time'=>$focus['time_label'],'leave_by'=>$focus['leave_by'],'traveler_state'=>$focus['traveler_state'],'countdown'=>$focus['countdown']['label']??'']:null,
                'high_ripples'=>(int)$snap['summary']['high_ripples'],'medium_ripples'=>(int)$snap['summary']['medium_ripples'],
                'required_checklist_open'=>(int)$snap['summary']['required_open'],
            ];
        }return $out;
    }

    public function runUpcoming(int $limit=25): array
    {
        if(!$this->ready())return ['checked'=>0,'ripples'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));
        $sql="SELECT id,user_id FROM dream_trips WHERE status<>'abandoned' AND operational_state IN ('ready','traveling') AND (end_date IS NULL OR end_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY)) ORDER BY COALESCE(travel_copilot_checked_at,'1970-01-01 00:00:00'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limit}";
        $rows=$this->pdo->query($sql)->fetchAll()?:[];$out=['checked'=>0,'ripples'=>0,'errors'=>0];
        foreach($rows as $row){$out['checked']++;try{$snap=$this->snapshot((int)$row['user_id'],(int)$row['id'],null,true);$out['ripples']+=count($snap['ripples']??[]);}catch(Throwable $e){$out['errors']++;error_log('Travel Day Copilot sync failed: '.$e->getMessage());}}
        return $out;
    }

    public function offlineSnapshot(array $snapshot): array
    {
        $focus=$snapshot['focus'];$safeFocus=$focus?[
            'key'=>$focus['key'],'type'=>$focus['type'],'title'=>$focus['title'],'time_label'=>$focus['time_label'],
            'leave_by'=>$focus['leave_by'],'arrive_by'=>$focus['arrive_by'],'location'=>$focus['location'],
            'traveler_state'=>$focus['traveler_state'],'countdown'=>$focus['countdown'],
        ]:null;
        $next=[];foreach(array_slice((array)$snapshot['next'],0,4) as $item)$next[]=['key'=>$item['key'],'type'=>$item['type'],'title'=>$item['title'],'time_label'=>$item['time_label'],'leave_by'=>$item['leave_by'],'location'=>$item['location'],'traveler_state'=>$item['traveler_state']];
        $checklist=[];foreach((array)$snapshot['checklist'] as $item)$checklist[]=['id'=>(int)$item['id'],'label'=>$item['label'],'required'=>!empty($item['required']),'completed'=>!empty($item['completed_at'])];
        $ripples=[];foreach(array_slice((array)$snapshot['ripples'],0,6) as $r)$ripples[]=['severity'=>$r['severity'],'title'=>$r['title'],'body'=>$r['body']];
        return ['version'=>2,'saved_at'=>date(DATE_ATOM),'trip_id'=>(int)$snapshot['trip_id'],'selected_date'=>$snapshot['selected_date'],'focus'=>$safeFocus,'next'=>$next,'checklist'=>$checklist,'ripples'=>$ripples,'briefing'=>$snapshot['briefing'],'privacy_note'=>'Offline Copilot excludes confirmation codes, payment data, provider URLs, private notes and exact coordinates.'];
    }

    private function emptySnapshot(int $tripId,?string $date,array $access): array
    {
        return ['ready'=>false,'trip_id'=>$tripId,'selected_date'=>$date?:date('Y-m-d'),'role'=>$access['role']??'viewer','can_plan'=>!empty($access['can_plan']),'focus'=>null,'next'=>[],'timeline'=>[],'ripples'=>[],'checklist'=>[],'briefing'=>null,'summary'=>['open_checklist'=>0,'required_open'=>0,'high_ripples'=>0,'medium_ripples'=>0,'timeline_items'=>0],'offline'=>[]];
    }

    private function states(int $userId,int $tripId): array
    {
        $q=$this->pdo->prepare('SELECT item_key,traveler_state,delay_minutes,observed_at FROM trip_copilot_item_states WHERE user_id=? AND dream_trip_id=?');$q->execute([$userId,$tripId]);$out=[];
        foreach($q->fetchAll()?:[] as $row)$out[(string)$row['item_key']]=$row;return $out;
    }

    private function focus(array $timeline,string $selectedDate): ?array
    {
        $eligible=array_values(array_filter($timeline,static fn(array $e):bool=>!in_array((string)($e['traveler_state']??''),['done','skipped'],true)));
        if(!$eligible)return null;$today=date('Y-m-d');$pick=null;
        if($selectedDate===$today){foreach($eligible as $e)if(($e['phase']??'')==='now'){$pick=$e;break;}if(!$pick)foreach($eligible as $e)if(($e['phase']??'')==='next'){$pick=$e;break;}}
        if(!$pick){$now=time();foreach($eligible as $e){$ts=(int)($e['start_ts']??0);if($ts>=$now||$selectedDate!==$today){$pick=$e;break;}}}
        if(!$pick)$pick=end($eligible)?:null;if(!$pick)return null;
        $target=!empty($pick['leave_by'])&&strtotime((string)$pick['leave_by'])?strtotime((string)$pick['leave_by']):((int)($pick['start_ts']??0)?:null);
        $pick['countdown']=$this->countdown($target,$pick);return $pick;
    }

    private function countdown(?int $target,array $item): array
    {
        if(!$target)return ['target_at'=>null,'seconds'=>null,'label'=>'Time TBD','state'=>'unknown'];$seconds=$target-time();
        if($seconds<=-1800)return ['target_at'=>date(DATE_ATOM,$target),'seconds'=>$seconds,'label'=>'Scheduled time passed','state'=>'past'];
        if($seconds<=0)return ['target_at'=>date(DATE_ATOM,$target),'seconds'=>$seconds,'label'=>!empty($item['leave_by'])?'Leave now':'Starting now','state'=>'now'];
        $minutes=(int)ceil($seconds/60);$label=$minutes<60?($minutes.' min'):((int)floor($minutes/60).'h '.($minutes%60).'m');
        return ['target_at'=>date(DATE_ATOM,$target),'seconds'=>$seconds,'label'=>(!empty($item['leave_by'])?'Leave in ':'Starts in ').$label,'state'=>$minutes<=30?'soon':'upcoming'];
    }

    private function nextItems(array $timeline,?array $focus,int $limit): array
    {
        $out=[];$focusKey=(string)($focus['key']??'');$seenFocus=$focusKey==='';
        foreach($timeline as $item){if(in_array((string)$item['traveler_state'],['done','skipped'],true))continue;if(!$seenFocus){if((string)$item['key']===$focusKey)$seenFocus=true;continue;}if((string)$item['key']===$focusKey)continue;$out[]=$item;if(count($out)>=$limit)break;}return $out;
    }

    private function ripples(array $timeline,array $itineraryIssues,?array $focus,string $date): array
    {
        $out=[];
        foreach($itineraryIssues as $issue){if(!in_array((string)$issue['severity'],['high','medium'],true))continue;$out[]=['key'=>'itinerary:'.$issue['key'],'severity'=>$issue['severity'],'title'=>$issue['title'],'body'=>$issue['body'],'source'=>'itinerary'];}
        foreach($timeline as $item){
            $ops=(string)($item['operational_status']??'unknown');if($ops==='cancelled')$out[]=['key'=>'cancelled:'.$item['key'],'severity'=>'high','title'=>$item['title'].' is recorded cancelled','body'=>'The saved operational status is cancelled. Review recovery options before relying on downstream timing.','source'=>'booking'];
            elseif($ops==='delayed')$out[]=['key'=>'delayed:'.$item['key'],'severity'=>'high','title'=>$item['title'].' is delayed','body'=>'The saved travel status is delayed. Vacation Brain is treating downstream timing as at risk until the itinerary is rechecked.','source'=>'booking'];
            if(($item['traveler_state']??'')==='running_late'){
                $delay=(int)($item['delay_minutes']??0);$out[]=['key'=>'late:'.$item['key'],'severity'=>$delay>=30?'high':'medium','title'=>'Running late for '.$item['title'],'body'=>'You reported about '.$delay.' minutes of delay. Review the next fixed commitment and leave-by target before continuing.','source'=>'traveler'];
            }
            if(($item['traveler_state']??'')==='need_help')$out[]=['key'=>'help:'.$item['key'],'severity'=>'high','title'=>'Help requested for '.$item['title'],'body'=>'A traveler marked this step as needing help. Vacation Brain can review saved trip facts and prepare recovery options, but cannot execute provider changes without approval.','source'=>'traveler'];
        }
        if($focus&&!empty($focus['leave_by'])&&($ts=strtotime((string)$focus['leave_by']))&&$date===date('Y-m-d')&&$ts<time()&&($focus['traveler_state']??'')!=='done')$out[]=['key'=>'leave-overdue:'.$focus['key'],'severity'=>'high','title'=>'Leave-by time has passed','body'=>$focus['title'].' has a saved leave-by target that is already past. Check the current travel status and downstream commitments now.','source'=>'copilot'];
        $unique=[];foreach($out as $r)$unique[$r['key']]=$r;return array_values($unique);
    }

    private function ensureChecklist(int $userId,int $tripId,string $date,array $timeline,array $trip): void
    {
        $items=[
            ['phone','personal','Phone charged and essential apps available',1,'system'],
            ['wallet','personal','Wallet / payment method accessible',1,'system'],
        ];
        $tripStart=(string)($trip['start_date']??'');if($tripStart===$date){$items[]=['id','documents','ID / passport in hand',1,'system'];$items[]=['bags','departure','Bags and essentials accounted for',1,'system'];}
        $types=[];foreach($timeline as $e)$types[(string)$e['type']]=true;
        if(isset($types['flight'])){$items[]=['boarding-pass','documents','Boarding pass or airline app ready',1,'booking'];$items[]=['airport-bags','departure','Baggage plan checked',0,'booking'];}
        if(isset($types['lodging']))$items[]=['lodging-details','lodging','Lodging check-in details ready',1,'booking'];
        if(isset($types['transport']))$items[]=['transport-details','transport','Transport / pickup details ready',1,'booking'];
        if(isset($types['event']))$items[]=['event-entry','event','Event tickets or entry details ready',1,'booking'];
        foreach($items as [$key,$category,$label,$required,$source]){
            $sql='INSERT IGNORE INTO trip_copilot_checklist_items (dream_trip_id,user_id,checklist_date,checklist_key,category,label,required,source_type,due_at) VALUES (?,?,?,?,?,?,?,?,?)';
            $this->pdo->prepare($sql)->execute([$tripId,$userId,$date,$key,$category,$label,$required,$source,$date.' 23:59:59']);
        }
    }

    private function checklist(int $userId,int $tripId,string $date): array
    {
        $q=$this->pdo->prepare('SELECT id,checklist_key,category,label,required,source_type,due_at,completed_at FROM trip_copilot_checklist_items WHERE user_id=? AND dream_trip_id=? AND checklist_date=? ORDER BY required DESC,completed_at IS NOT NULL,FIELD(category,\'documents\',\'departure\',\'transport\',\'lodging\',\'event\',\'arrival\',\'personal\'),id');
        $q->execute([$userId,$tripId,$date]);$rows=$q->fetchAll()?:[];foreach($rows as &$r){$r['id']=(int)$r['id'];$r['required']=!empty($r['required']);}unset($r);return $rows;
    }

    private function briefing(int $userId,int $tripId,string $date,array $timeline,?array $focus,array $ripples,array $checklist,bool $sync): ?array
    {
        $type=$this->briefingType($date,$focus);$open=count(array_filter($checklist,static fn(array $r):bool=>empty($r['completed_at'])));$high=count(array_filter($ripples,static fn(array $r):bool=>$r['severity']==='high'));
        $next=array_slice(array_values(array_filter($timeline,static fn(array $e):bool=>!in_array((string)($e['traveler_state']??''),['done','skipped'],true))),0,4);
        $title=match($type){'morning'=>'Today’s travel brief','pre_departure'=>'Departure brief','arrival'=>'Arrival brief','end_of_day'=>'Day wrap'};
        $bits=[];$bits[]=$focus?'Next focus: '.$focus['title'].' at '.$focus['time_label'].'.':'No active timed commitment is saved for this day.';
        if($focus&&!empty($focus['leave_by']))$bits[]='Leave-by target: '.date('g:i A',strtotime((string)$focus['leave_by'])).'.';
        if($high>0)$bits[]=$high.' high-priority timing or disruption issue'.($high===1?' needs':'s need').' attention.';
        if($open>0)$bits[]=$open.' checklist item'.($open===1?' remains':'s remain').' open.';
        if(count($next)>1)$bits[]='Upcoming: '.implode(', ',array_map(static fn(array $e):string=>(string)$e['title'],$next)).'.';
        $body=implode(' ',$bits);$fingerprint=hash('sha256',json_encode([$type,$date,$body],JSON_UNESCAPED_SLASHES));
        if($sync){$sql='INSERT INTO trip_copilot_briefings (dream_trip_id,user_id,briefing_date,briefing_type,title,body,source_fingerprint,generated_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),source_fingerprint=VALUES(source_fingerprint),generated_at=IF(source_fingerprint<>VALUES(source_fingerprint),NOW(),generated_at),updated_at=NOW()';$this->pdo->prepare($sql)->execute([$tripId,$userId,$date,$type,$title,$body,$fingerprint]);}
        return ['type'=>$type,'title'=>$title,'body'=>$body,'fingerprint'=>$fingerprint];
    }

    private function briefingType(string $date,?array $focus): string
    {
        if($date!==date('Y-m-d'))return 'morning';$hour=(int)date('G');if($hour<10)return 'morning';
        if($focus&&in_array((string)$focus['type'],['flight','transport'],true))return 'pre_departure';
        if($focus&&($focus['type']??'')==='lodging')return 'arrival';if($hour>=19)return 'end_of_day';return 'pre_departure';
    }

    private function syncInbox(int $userId,int $tripId,array $ripples,string $date): void
    {
        if(!db_table_exists('trip_inbox_items'))return;$active=[];
        foreach($ripples as $r){if($r['severity']==='low')continue;$key='ripple:'.$r['key'];$active[]=$key;$this->upsertInboxItem($userId,$tripId,$key,$r['severity'],$r['severity']==='high'?'now':'today',$r['title'],$r['body'],true,$date);}
        $params=[$userId,$tripId,'travel_copilot'];$sql='UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),source_status=\'resolved\',updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND source_type=? AND resolved_at IS NULL';
        if($active){$sql.=' AND source_key NOT IN ('.implode(',',array_fill(0,count($active),'?')).')';$params=array_merge($params,$active);}$this->pdo->prepare($sql)->execute($params);
    }

    private function upsertInboxItem(int $userId,int $tripId,string $sourceKey,string $severity,string $urgency,string $title,string $body,bool $requiresAction,string $date): void
    {
        if(!db_table_exists('trip_inbox_items'))return;$priority=$severity==='high'?99:84;$fingerprint=hash('sha256',json_encode([$severity,$urgency,$title,$body,$date],JSON_UNESCAPED_SLASHES));
        $sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,due_at,occurred_at) VALUES (?,?,?,?,?,'risk',?,?,?,?,?,?,?, ?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE priority=VALUES(priority),urgency=VALUES(urgency),title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status=VALUES(source_status),requires_action=VALUES(requires_action),read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,resolved_at),snoozed_until=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,snoozed_until),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";
        $this->pdo->prepare($sql)->execute([$userId,$tripId,'travel_copilot',$sourceKey,'copilot:'.$date,$priority,$urgency,$title,$body,'Open Travel Mode',app_url('travel-mode.php?id='.$tripId.'&date='.rawurlencode($date)),$severity,$requiresAction?1:0,$fingerprint]);
    }

    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Travel Day Copilot 2.0.');}
}
