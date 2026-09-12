<?php
declare(strict_types=1);

/**
 * User-specific, permission-aware projection of canonical trip activity.
 * Source systems remain authoritative; this service stores only normalized safe
 * summaries plus local inbox state (read/resolved/snoozed).
 */
final class TripUnifiedInboxService
{
    private const ACTIONABLE_SOURCES=['proactive','agent_action','booking_import','booking_change','booking_action'];
    private const URGENCY_RANK=['optional'=>0,'before_trip'=>1,'today'=>2,'now'=>3];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_inbox_items')
            && db_table_exists('trip_inbox_events')
            && db_table_exists('trip_inbox_preferences')
            && db_table_exists('trip_inbox_trip_preferences');
    }

    public function accessibleTrips(int $userId): array
    {
        if(!$this->ready())return [];
        $owned=$this->pdo->prepare("SELECT dt.id,dt.user_id owner_user_id,dt.name,dt.status,dt.operational_state,dt.start_date,dt.end_date,'owner' role,COALESCE(tp.muted,0) muted
            FROM dream_trips dt LEFT JOIN trip_inbox_trip_preferences tp ON tp.user_id=? AND tp.dream_trip_id=dt.id
            WHERE dt.user_id=? AND dt.status<>'abandoned' ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.id");
        $owned->execute([$userId,$userId]);$rows=$owned->fetchAll()?:[];
        if(db_table_exists('trip_collaborators')){
            $shared=$this->pdo->prepare("SELECT dt.id,dt.user_id owner_user_id,dt.name,dt.status,dt.operational_state,dt.start_date,dt.end_date,tc.role,COALESCE(tp.muted,0) muted
                FROM trip_collaborators tc JOIN dream_trips dt ON dt.id=tc.dream_trip_id AND dt.status<>'abandoned'
                LEFT JOIN trip_inbox_trip_preferences tp ON tp.user_id=tc.user_id AND tp.dream_trip_id=dt.id
                WHERE tc.user_id=? AND tc.status='active' ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.id");
            $shared->execute([$userId]);foreach($shared->fetchAll()?:[] as $r)$rows[]=$r;
        }
        foreach($rows as &$r){$r['id']=(int)$r['id'];$r['owner_user_id']=(int)$r['owner_user_id'];$r['muted']=!empty($r['muted']);}unset($r);
        return $rows;
    }

    public function syncUser(int $userId,?int $onlyTripId=null): array
    {
        if(!$this->ready())return ['ready'=>false,'trips'=>0,'items'=>0];
        $trips=$this->accessibleTrips($userId);if($onlyTripId!==null){$trips=array_values(array_filter($trips,fn($t)=>(int)$t['id']===$onlyTripId));if(!$trips)throw new OutOfBoundsException('Trip not found or permission denied.');}
        $count=0;
        foreach($trips as $trip){$tripId=(int)$trip['id'];$role=(string)$trip['role'];
            if($role==='owner'){
                $count+=$this->syncProactive($userId,$trip);
                $count+=$this->syncAgentActions($userId,$trip);
                $count+=$this->syncAgentJobs($userId,$trip);
                $count+=$this->syncBookingImports($userId,$tripId);
                $count+=$this->syncBookingChanges($userId,$tripId);
                $count+=$this->syncBookingActions($userId,$tripId);
                $count+=$this->syncWatchEvents($userId,$tripId);
                $count+=$this->syncBookingDeadlines($userId,$trip);
            }
            if(in_array($role,['owner','co_planner','traveler'],true))$count+=$this->syncOperations($userId,$tripId);
            $count+=$this->syncCollaboration($userId,$tripId,$role);
        }
        if($onlyTripId===null)$count+=$this->syncUnassignedImports($userId);
        return ['ready'=>true,'trips'=>count($trips),'items'=>$count];
    }

    public function syncActiveUsers(int $limit=20): array
    {
        if(!$this->ready())return ['users'=>0,'items'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));
        $sql="SELECT user_id FROM (SELECT DISTINCT user_id FROM dream_trips WHERE status<>'abandoned' UNION SELECT DISTINCT user_id FROM trip_collaborators WHERE status='active') x LIMIT {$limit}";
        $rows=$this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN)?:[];$out=['users'=>0,'items'=>0,'errors'=>0];
        foreach($rows as $uid){$out['users']++;try{$r=$this->syncUser((int)$uid);$out['items']+=(int)($r['items']??0);}catch(Throwable $e){$out['errors']++;error_log('Unified Trip Inbox sync failed: '.$e->getMessage());}}
        return $out;
    }

    public function threads(int $userId,?int $tripId=null,string $filter='open',int $limit=120): array
    {
        if(!$this->ready())return [];$limit=max(1,min(250,$limit));$filter=in_array($filter,['open','unread','resolved','all'],true)?$filter:'open';$params=[$userId];$where='i.user_id=?';
        if($tripId!==null){$where.=' AND i.dream_trip_id=?';$params[]=$tripId;}
        if($filter==='open')$where.=' AND i.resolved_at IS NULL AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())';
        elseif($filter==='unread')$where.=' AND i.resolved_at IS NULL AND i.read_at IS NULL AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())';
        elseif($filter==='resolved')$where.=' AND i.resolved_at IS NOT NULL';
        $sql="SELECT i.*,dt.name trip_name,COALESCE(tp.muted,0) trip_muted FROM trip_inbox_items i LEFT JOIN dream_trips dt ON dt.id=i.dream_trip_id LEFT JOIN trip_inbox_trip_preferences tp ON tp.user_id=i.user_id AND tp.dream_trip_id=i.dream_trip_id WHERE {$where} ORDER BY i.priority DESC,COALESCE(i.due_at,i.occurred_at) ASC,i.occurred_at DESC,i.id DESC LIMIT {$limit}";
        $q=$this->pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll()?:[];$threads=[];
        foreach($rows as $row){$row=$this->castItem($row);$key=(string)$row['thread_key'];if(!isset($threads[$key]))$threads[$key]=['thread_key'=>$key,'trip_id'=>$row['dream_trip_id'],'trip_name'=>$row['trip_name'],'priority'=>(int)$row['priority'],'urgency'=>(string)$row['urgency'],'requires_action'=>false,'unread_count'=>0,'count'=>0,'muted'=>!empty($row['trip_muted']),'items'=>[]];$t=&$threads[$key];$t['priority']=max((int)$t['priority'],(int)$row['priority']);if(self::URGENCY_RANK[(string)$row['urgency']] > self::URGENCY_RANK[(string)$t['urgency']])$t['urgency']=$row['urgency'];$t['requires_action']=$t['requires_action']||!empty($row['requires_action']);$t['unread_count']+=empty($row['read_at'])?1:0;$t['count']++;$t['items'][]=$row;unset($t);}
        $threads=array_values($threads);usort($threads,fn($a,$b)=>((int)$b['priority']<=>(int)$a['priority']) ?: (self::URGENCY_RANK[$b['urgency']]<=>self::URGENCY_RANK[$a['urgency']]));return $threads;
    }

    public function summary(int $userId,?int $tripId=null): array
    {
        if(!$this->ready())return ['unread'=>0,'needs_action'=>0,'now'=>0,'today'=>0,'snoozed'=>0];$params=[$userId];$where='i.user_id=? AND i.resolved_at IS NULL';if($tripId!==null){$where.=' AND i.dream_trip_id=?';$params[]=$tripId;}else $where.=' AND (i.dream_trip_id IS NULL OR COALESCE(tp.muted,0)=0)';
        $sql="SELECT SUM(i.read_at IS NULL AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())) unread,SUM(i.requires_action=1 AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())) needs_action,SUM(i.urgency='now' AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())) now_count,SUM(i.urgency='today' AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW())) today_count,SUM(i.snoozed_until>NOW()) snoozed FROM trip_inbox_items i LEFT JOIN trip_inbox_trip_preferences tp ON tp.user_id=i.user_id AND tp.dream_trip_id=i.dream_trip_id WHERE {$where}";
        $q=$this->pdo->prepare($sql);$q->execute($params);$r=$q->fetch()?:[];return ['unread'=>(int)($r['unread']??0),'needs_action'=>(int)($r['needs_action']??0),'now'=>(int)($r['now_count']??0),'today'=>(int)($r['today_count']??0),'snoozed'=>(int)($r['snoozed']??0)];
    }

    public function unreadCount(int $userId): int{return (int)$this->summary($userId)['unread'];}

    public function preferences(int $userId): array
    {
        $defaults=['delivery_mode'=>'all','immediate_disruptions'=>1,'digest_hour'=>8];if(!$this->ready())return $defaults;$q=$this->pdo->prepare('SELECT delivery_mode,immediate_disruptions,digest_hour FROM trip_inbox_preferences WHERE user_id=?');$q->execute([$userId]);$r=$q->fetch();return $r?array_merge($defaults,$r):$defaults;
    }

    public function savePreferences(int $userId,array $input): array
    {
        $this->requireReady();$mode=strtolower(trim((string)($input['delivery_mode']??'all')));if(!in_array($mode,['all','important_only','daily_digest'],true))$mode='all';$immediate=!empty($input['immediate_disruptions'])?1:0;$hour=max(0,min(23,(int)($input['digest_hour']??8)));
        $this->pdo->prepare('INSERT INTO trip_inbox_preferences (user_id,delivery_mode,immediate_disruptions,digest_hour) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE delivery_mode=VALUES(delivery_mode),immediate_disruptions=VALUES(immediate_disruptions),digest_hour=VALUES(digest_hour),updated_at=NOW()')->execute([$userId,$mode,$immediate,$hour]);return $this->preferences($userId);
    }

    public function setTripMuted(int $userId,int $tripId,bool $muted): void
    {
        $this->requireReady();$this->assertInboxTripAccess($userId,$tripId);$this->pdo->prepare('INSERT INTO trip_inbox_trip_preferences (user_id,dream_trip_id,muted) VALUES (?,?,?) ON DUPLICATE KEY UPDATE muted=VALUES(muted),updated_at=NOW()')->execute([$userId,$tripId,$muted?1:0]);
    }

    public function markRead(int $userId,int $itemId): void{$this->stateChange($userId,$itemId,'read',null);}
    public function resolve(int $userId,int $itemId): void{$this->stateChange($userId,$itemId,'resolved',null);}
    public function reopen(int $userId,int $itemId): void{$this->stateChange($userId,$itemId,'reopened',null);}
    public function unsnooze(int $userId,int $itemId): void{$this->stateChange($userId,$itemId,'unsnoozed',null);}
    public function snooze(int $userId,int $itemId,string $preset): void
    {
        $until=match($preset){'1h'=>(new DateTimeImmutable())->modify('+1 hour'),'tomorrow'=>(new DateTimeImmutable('tomorrow'))->setTime(8,0),'week'=>(new DateTimeImmutable())->modify('+7 days')->setTime(8,0),default=>throw new InvalidArgumentException('Unknown snooze period.')};$this->stateChange($userId,$itemId,'snoozed',$until->format('Y-m-d H:i:s'));
    }

    public function safeAgentContext(int $userId,int $limit=8): array
    {
        if(!$this->ready())return [];$limit=max(1,min(20,$limit));$q=$this->pdo->prepare("SELECT i.item_type,i.urgency,i.title,i.body,i.source_status,i.requires_action,i.due_at,i.occurred_at,dt.name trip_name FROM trip_inbox_items i LEFT JOIN dream_trips dt ON dt.id=i.dream_trip_id LEFT JOIN trip_inbox_trip_preferences tp ON tp.user_id=i.user_id AND tp.dream_trip_id=i.dream_trip_id WHERE i.user_id=? AND i.resolved_at IS NULL AND (i.snoozed_until IS NULL OR i.snoozed_until<=NOW()) AND (i.dream_trip_id IS NULL OR COALESCE(tp.muted,0)=0) ORDER BY i.requires_action DESC,i.priority DESC,i.occurred_at DESC LIMIT {$limit}");$q->execute([$userId]);return $q->fetchAll()?:[];
    }

    public function augmentCommandCenterSnapshot(int $userId,array $snapshot): array
    {
        if(!$this->ready())return $snapshot;$prefs=$this->preferences($userId);$summary=$this->summary($userId);$snapshot['summary']['trip_inbox_unread']=$summary['unread'];$snapshot['summary']['trip_inbox_needs_action']=$summary['needs_action'];
        $shouldSurface=$prefs['delivery_mode']!=='daily_digest'||(!empty($prefs['immediate_disruptions'])&&$summary['now']>0);if($summary['needs_action']>0&&$shouldSurface){$item=['key'=>'unified-trip-inbox','kind'=>'inbox','priority'=>99,'title'=>$summary['needs_action']===1?'Trip Inbox needs your attention':'Trip Inbox has '.$summary['needs_action'].' items needing attention','body'=>'Booking changes, agent work, trip risks, reminders and collaboration activity are threaded in one place.','trip_name'=>'','url'=>app_url('trip-inbox.php'),'cta'=>'Open inbox'];$attention=is_array($snapshot['attention']??null)?$snapshot['attention']:[];array_unshift($attention,$item);$snapshot['attention']=array_slice($attention,0,8);$snapshot['summary']['needs_you']=count($snapshot['attention']);$snapshot['status']='Needs your attention';}return $snapshot;
    }

    private function stateChange(int $userId,int $itemId,string $event,?string $until): void
    {
        $this->requireReady();$q=$this->pdo->prepare('SELECT id FROM trip_inbox_items WHERE id=? AND user_id=? LIMIT 1');$q->execute([$itemId,$userId]);if(!$q->fetchColumn())throw new OutOfBoundsException('Trip Inbox item not found.');
        $this->pdo->beginTransaction();try{
            if($event==='read')$this->pdo->prepare('UPDATE trip_inbox_items SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$itemId,$userId]);
            elseif($event==='resolved')$this->pdo->prepare('UPDATE trip_inbox_items SET read_at=COALESCE(read_at,NOW()),resolved_at=COALESCE(resolved_at,NOW()),snoozed_until=NULL WHERE id=? AND user_id=?')->execute([$itemId,$userId]);
            elseif($event==='reopened')$this->pdo->prepare('UPDATE trip_inbox_items SET resolved_at=NULL,snoozed_until=NULL WHERE id=? AND user_id=?')->execute([$itemId,$userId]);
            elseif($event==='snoozed')$this->pdo->prepare('UPDATE trip_inbox_items SET snoozed_until=?,read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([$until,$itemId,$userId]);
            elseif($event==='unsnoozed')$this->pdo->prepare('UPDATE trip_inbox_items SET snoozed_until=NULL WHERE id=? AND user_id=?')->execute([$itemId,$userId]);
            else throw new InvalidArgumentException('Unknown Trip Inbox action.');
            $this->pdo->prepare('INSERT INTO trip_inbox_events (trip_inbox_item_id,user_id,event_type,detail_json) VALUES (?,?,?,?)')->execute([$itemId,$userId,$event,$until?json_encode(['until'=>$until],JSON_UNESCAPED_SLASHES):null]);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function syncProactive(int $userId,array $trip): int
    {
        if(!db_table_exists('trip_proactive_issues'))return 0;$tripId=(int)$trip['id'];$q=$this->pdo->prepare("SELECT id,issue_key,issue_type,severity,title,body,target_tab,status,last_seen_at,updated_at FROM trip_proactive_issues WHERE user_id=? AND dream_trip_id=? AND status='open' ORDER BY FIELD(severity,'critical','high','medium','low','info'),last_seen_at DESC");$q->execute([$userId,$tripId]);$seen=[];$n=0;
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];$seen[]=$key;$sev=(string)$r['severity'];$priority=match($sev){'critical'=>100,'high'=>92,'medium'=>78,'low'=>58,default=>42};$urgency=match($sev){'critical'=>'now','high'=>'today','medium'=>'before_trip',default=>'optional'};$this->upsert($userId,$tripId,'proactive',$key,'proactive:'.(string)$r['issue_key'],'risk',$priority,$urgency,(string)$r['title'],(string)$r['body'],'Review risk',app_url('proactive-trip.php?id='.$tripId),(string)$r['status'],true,null,(string)($r['last_seen_at']?:$r['updated_at']));$n++;}
        $this->autoResolveMissing($userId,$tripId,'proactive',$seen);return $n;
    }

    private function syncAgentActions(int $userId,array $trip): int
    {
        if(!db_table_exists('trip_agent_actions'))return 0;$tripId=(int)$trip['id'];$q=$this->pdo->prepare("SELECT id,suggestion_key,agent_type,action_kind,title,body,priority,target_tab,status,updated_at,completed_at FROM trip_agent_actions WHERE user_id=? AND dream_trip_id=? AND (status IN ('open','accepted') OR (status='completed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY))) ORDER BY priority DESC,updated_at DESC");$q->execute([$userId,$tripId]);$seen=[];$n=0;
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];if((string)$r['status']!=='completed')$seen[]=$key;$status=(string)$r['status'];$requires=$status==='open';$type=$status==='completed'?'agent_result':'review';$priority=max(30,min(100,(int)$r['priority']));$urgency=$requires?($priority>=90?'now':($priority>=75?'today':'before_trip')):'optional';$this->upsert($userId,$tripId,'agent_action',$key,'agent:'.(string)$r['agent_type'].':'.(string)$r['suggestion_key'],$type,$priority,$urgency,(string)$r['title'],(string)$r['body'],$requires?'Review Next Move':'Open agent result',app_url('dream-trip.php?id='.$tripId.'&workspace=next#next-moves'),$status,$requires,null,(string)($r['completed_at']?:$r['updated_at']));$n++;}
        $this->autoResolveMissing($userId,$tripId,'agent_action',$seen);return $n;
    }

    private function syncAgentJobs(int $userId,array $trip): int
    {
        if(!db_table_exists('trip_agent_jobs'))return 0;$tripId=(int)$trip['id'];$q=$this->pdo->prepare("SELECT id,agent_type,status,status_text,error_message,completed_at,updated_at FROM trip_agent_jobs WHERE user_id=? AND dream_trip_id=? AND status IN ('completed','failed') AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) ORDER BY updated_at DESC LIMIT 80");$q->execute([$userId,$tripId]);$n=0;
        foreach($q->fetchAll()?:[] as $r){$failed=(string)$r['status']==='failed';$agent=ucfirst((string)$r['agent_type']).' agent';$body=$failed?'The specialist could not finish this run. Open the trip to retry or inspect the saved error state.':((string)($r['status_text']?:'Specialist work completed and is saved on the trip.'));$this->upsert($userId,$tripId,'agent_job',(string)$r['id'],'agent:'.(string)$r['agent_type'],$failed?'risk':'agent_result',$failed?86:48,$failed?'today':'optional',$failed?$agent.' needs attention':$agent.' finished',$body,'Open specialist',app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode((string)$r['agent_type']).'#agent-results'),(string)$r['status'],$failed,null,(string)($r['completed_at']?:$r['updated_at']));$n++;}return $n;
    }

    private function syncBookingImports(int $userId,int $tripId): int
    {
        if(!db_table_exists('trip_booking_imports'))return 0;$q=$this->pdo->prepare("SELECT id,booking_id,status,source_type,match_confidence,match_reason,updated_at,verified_at FROM trip_booking_imports WHERE user_id=? AND dream_trip_id=? AND (status IN ('matched','needs_review') OR (status='verified' AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY))) ORDER BY updated_at DESC");$q->execute([$userId,$tripId]);$seen=[];$n=0;
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];$status=(string)$r['status'];if(in_array($status,['matched','needs_review'],true))$seen[]=$key;$requires=in_array($status,['matched','needs_review'],true);$title=$requires?'Review imported reservation':'Imported reservation verified';$body=$requires?'Vacation Brain parsed a confirmation and matched it to this trip. Review the normalized facts before treating them as traveler-verified.':'The traveler reviewed the normalized imported reservation facts.';$thread=!empty($r['booking_id'])?'booking:'.(int)$r['booking_id']:'import:'.$key;$this->upsert($userId,$tripId,'booking_import',$key,$thread,$requires?'review':'info',$requires?88:42,$requires?'today':'optional',$title,$body,$requires?'Review import':'Open Booking Inbox',app_url('booking-inbox.php?review='.(int)$r['id']),$status,$requires,null,(string)($r['verified_at']?:$r['updated_at']));$n++;}
        $this->autoResolveMissing($userId,$tripId,'booking_import',$seen);return $n;
    }

    private function syncUnassignedImports(int $userId): int
    {
        if(!db_table_exists('trip_booking_imports'))return 0;$q=$this->pdo->prepare("SELECT id,status,updated_at FROM trip_booking_imports WHERE user_id=? AND dream_trip_id IS NULL AND status IN ('matched','needs_review') ORDER BY updated_at DESC");$q->execute([$userId]);$seen=[];$n=0;foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];$seen[]=$key;$this->upsert($userId,null,'booking_import',$key,'import:'.$key,'review',90,'today','Booking confirmation needs a trip','Vacation Brain parsed a confirmation but it still needs a trip assignment and traveler review.','Review import',app_url('booking-inbox.php?review='.(int)$r['id']),(string)$r['status'],true,null,(string)$r['updated_at']);$n++;}$this->autoResolveMissing($userId,null,'booking_import',$seen);return $n;
    }

    private function syncBookingChanges(int $userId,int $tripId): int
    {
        if(!db_table_exists('booking_change_proposals'))return 0;$q=$this->pdo->prepare("SELECT c.id,c.booking_id,c.change_type,c.status,c.diff_json,c.applied_at,c.updated_at,b.title booking_title FROM booking_change_proposals c JOIN trip_bookings b ON b.id=c.booking_id AND b.user_id=c.user_id WHERE c.user_id=? AND c.dream_trip_id=? AND (c.status='needs_review' OR (c.status='applied' AND c.updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY))) ORDER BY c.updated_at DESC");$q->execute([$userId,$tripId]);$seen=[];$n=0;
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];$needs=(string)$r['status']==='needs_review';if($needs)$seen[]=$key;$diff=json_decode((string)$r['diff_json'],true);$fields=is_array($diff)?array_keys($diff):[];$fieldText=$fields?implode(', ',array_map(fn($v)=>str_replace('_',' ',$v),array_slice($fields,0,5))):'reservation facts';$cancel=(string)$r['change_type']==='cancelled';$title=$needs?($cancel?'Email reports a cancellation':'Reservation change needs review'):($cancel?'Cancellation was recorded':'Reservation change applied');$body=$needs?'Connected mail reports a change to '.$fieldText.' for '.(string)$r['booking_title'].'. Review the redacted source and before/after diff; sender identity is not provider verification.':'The traveler reviewed and applied this email-reported factual update.';$this->upsert($userId,$tripId,'booking_change',$key,'booking:'.(int)$r['booking_id'],$needs?'booking_change':'info',$needs?96:45,$needs?'now':'optional',$title,$body,$needs?'Review change':'Open connected mail',app_url('booking-mailbox.php'),(string)$r['status'],$needs,null,(string)($r['applied_at']?:$r['updated_at']));$n++;}
        $this->autoResolveMissing($userId,$tripId,'booking_change',$seen);return $n;
    }

    private function syncBookingActions(int $userId,int $tripId): int
    {
        if(!db_table_exists('trip_booking_action_intents'))return 0;$q=$this->pdo->prepare("SELECT id,booking_id,action_type,provider_slug,status,error_message,updated_at,completed_at FROM trip_booking_action_intents WHERE user_id=? AND dream_trip_id=? AND (status IN ('awaiting_approval','approved','executing','handoff_pending','verification_pending','failed') OR (status='completed' AND updated_at>=DATE_SUB(NOW(),INTERVAL 14 DAY))) ORDER BY updated_at DESC");$q->execute([$userId,$tripId]);$seen=[];$n=0;
        foreach($q->fetchAll()?:[] as $r){$key=(string)$r['id'];$status=(string)$r['status'];$requires=in_array($status,['awaiting_approval','handoff_pending','verification_pending','failed'],true);if($requires)$seen[]=$key;$type=match($status){'awaiting_approval','handoff_pending'=>'approval','verification_pending','failed'=>'risk','completed'=>'info',default=>'info'};$priority=match($status){'verification_pending'=>100,'awaiting_approval'=>97,'failed'=>94,'handoff_pending'=>90,'approved','executing'=>72,'completed'=>45,default=>55};$urgency=$priority>=95?'now':($priority>=80?'today':'optional');$action=ucwords(str_replace('_',' ',(string)$r['action_type']));$provider=ucwords(str_replace('_',' ',(string)$r['provider_slug']));$title=$action.' · '.ucwords(str_replace('_',' ',$status));$body=match($status){'awaiting_approval'=>'A locked transaction quote is waiting for explicit approval. Nothing has been executed yet.','handoff_pending'=>'Provider checkout was opened; Vacation Brain is waiting for the traveler to record the outcome.','verification_pending'=>'The provider action outcome is uncertain. Do not retry until provider state is verified.','failed'=>'The provider action failed or could not be completed safely. Review the canonical transaction ledger before retrying.','completed'=>'The saved transaction ledger is completed. Provider-handoff completion remains user-confirmed Booked, not provider-verified Confirmed.',default=>$action.' via '.$provider.' is '.str_replace('_',' ',$status).'.'};$thread=!empty($r['booking_id'])?'booking:'.(int)$r['booking_id']:'booking-action:'.$key;$this->upsert($userId,$tripId,'booking_action',$key,$thread,$type,$priority,$urgency,$title,$body,$requires?'Review approval':'Open booking action',app_url('booking-action.php?id='.$tripId.'&intent='.(int)$r['id']),$status,$requires,null,(string)($r['completed_at']?:$r['updated_at']));$n++;}
        $this->autoResolveMissing($userId,$tripId,'booking_action',$seen);return $n;
    }

    private function syncWatchEvents(int $userId,int $tripId): int
    {
        if(!db_table_exists('travel_watch_events')||!db_table_exists('travel_watches'))return 0;$q=$this->pdo->prepare("SELECT e.id,e.watch_id,e.data_type,e.event_type,e.direction,e.title,e.body,e.created_at FROM travel_watch_events e JOIN travel_watches w ON w.id=e.watch_id WHERE e.user_id=? AND w.dream_trip_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY e.created_at DESC LIMIT 100");$q->execute([$userId,$tripId]);$n=0;foreach($q->fetchAll()?:[] as $r){$direction=strtolower((string)$r['direction']);$priority=in_array($direction,['worse','down','alert'],true)?82:58;$urgency=$priority>=80?'today':'before_trip';$this->upsert($userId,$tripId,'watch_event',(string)$r['id'],'watch:'.(int)$r['watch_id'].':'.(string)$r['data_type'],'risk',$priority,$urgency,(string)$r['title'],(string)$r['body'],'Open watches',app_url('watches.php'),(string)$r['event_type'],false,null,(string)$r['created_at']);$n++;}return $n;
    }

    private function syncOperations(int $userId,int $tripId): int
    {
        if(!db_table_exists('trip_operation_events'))return 0;$q=$this->pdo->prepare("SELECT id,booking_id,event_type,severity,title,body,created_at FROM trip_operation_events WHERE dream_trip_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY created_at DESC LIMIT 120");$q->execute([$tripId]);$n=0;foreach($q->fetchAll()?:[] as $r){$sev=(string)$r['severity'];$priority=match($sev){'high'=>94,'medium'=>76,default=>52};$urgency=$sev==='high'?'now':($sev==='medium'?'today':'before_trip');$thread=!empty($r['booking_id'])?'booking:'.(int)$r['booking_id']:'operations:'.(string)$r['event_type'];$this->upsert($userId,$tripId,'operation_event',(string)$r['id'],$thread,$sev==='high'?'risk':'info',$priority,$urgency,(string)$r['title'],(string)$r['body'],'Open Travel Mode',app_url('travel-mode.php?id='.$tripId),(string)$r['event_type'],false,null,(string)$r['created_at']);$n++;}return $n;
    }

    private function syncBookingDeadlines(int $userId,array $trip): int
    {
        if(!db_table_exists('trip_bookings'))return 0;$tripId=(int)$trip['id'];$q=$this->pdo->prepare("SELECT id,title,booking_type,status,cancellation_deadline,checkin_opens_at FROM trip_bookings WHERE user_id=? AND dream_trip_id=? AND status<>'cancelled' AND ((cancellation_deadline BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 30 DAY)) OR (checkin_opens_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 30 DAY)))");$q->execute([$userId,$tripId]);$n=0;foreach($q->fetchAll()?:[] as $r){foreach([['cancellation_deadline','Cancellation deadline','Review booking'],['checkin_opens_at','Check-in opens','Open Travel Mode']] as [$field,$label,$action]){$due=(string)($r[$field]??'');if($due==='')continue;$hours=((strtotime($due)?:time())-time())/3600;$urgency=$hours<=6?'now':($hours<=24?'today':'before_trip');$priority=$field==='cancellation_deadline'?($hours<=24?95:82):($hours<=24?88:72);$sourceKey=(int)$r['id'].':'.$field;$body=(string)$r['title'].' · '.$label.' '.date('M j, Y g:i A',strtotime($due)?:time()).'.';$url=$field==='checkin_opens_at'?app_url('travel-mode.php?id='.$tripId):app_url('trip-bookings.php?id='.$tripId);$this->upsert($userId,$tripId,'booking_deadline',$sourceKey,'booking:'.(int)$r['id'],'reminder',$priority,$urgency,$label.' · '.(string)$r['title'],$body,$action,$url,$field,true,$due,date('Y-m-d H:i:s'));$n++;}}return $n;
    }

    private function syncCollaboration(int $userId,int $tripId,string $role): int
    {
        if(!db_table_exists('trip_collaboration_events'))return 0;$q=$this->pdo->prepare("SELECT e.id,e.event_type,e.actor_user_id,e.subject_user_id,e.dream_trip_item_id,e.detail_json,e.created_at,u.display_name actor_name FROM trip_collaboration_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE e.dream_trip_id=? AND e.created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY e.created_at DESC LIMIT 120");$q->execute([$tripId]);$n=0;foreach($q->fetchAll()?:[] as $r){$event=(string)$r['event_type'];$actor=trim((string)($r['actor_name']??''));if($actor==='')$actor='A traveler';$title=match($event){'joined'=>$actor.' joined the trip','role_changed'=>'Traveler role changed','rsvp_changed'=>$actor.' changed RSVP','item_added'=>$actor.' added an itinerary item','item_removed'=>$actor.' removed an itinerary item','vote_changed'=>$actor.' voted on the itinerary','member_removed'=>'Trip membership changed','invited'=>'Trip invitation created','invite_revoked'=>'Trip invitation revoked',default=>'Shared trip updated'};$priority=in_array($event,['role_changed','member_removed'],true)?68:48;$thread=!empty($r['dream_trip_item_id'])?'collab:item:'.(int)$r['dream_trip_item_id']:'collab:membership';$this->upsert($userId,$tripId,'collaboration',(string)$r['id'],$thread,'collaboration',$priority,'optional',$title,'Shared-trip activity was recorded. Open the shared workspace for the role-appropriate itinerary and traveler view.','Open shared trip',app_url('shared-trip.php?id='.$tripId),$event,false,null,(string)$r['created_at']);$n++;}return $n;
    }

    private function upsert(int $userId,?int $tripId,string $sourceType,string $sourceKey,string $threadKey,string $itemType,int $priority,string $urgency,string $title,string $body,?string $actionLabel,?string $actionUrl,?string $sourceStatus,bool $requiresAction,?string $dueAt,string $occurredAt): void
    {
        $priority=max(0,min(100,$priority));if(!isset(self::URGENCY_RANK[$urgency]))$urgency='optional';$title=$this->clip($title,180);$body=$this->clip($body,700);$sourceKey=$this->clip($sourceKey,190);$threadKey=$this->clip($threadKey,190);$actionLabel=$actionLabel!==null?$this->clip($actionLabel,80):null;$sourceStatus=$sourceStatus!==null?$this->clip($sourceStatus,48):null;if($occurredAt===''||!strtotime($occurredAt))$occurredAt=date('Y-m-d H:i:s');if($dueAt!==null&&!strtotime($dueAt))$dueAt=null;
        $fingerprint=hash('sha256',json_encode([$tripId,$itemType,$priority,$urgency,$title,$body,$actionLabel,$actionUrl,$sourceStatus,$requiresAction,$dueAt],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,due_at,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE dream_trip_id=VALUES(dream_trip_id),thread_key=VALUES(thread_key),item_type=VALUES(item_type),priority=VALUES(priority),urgency=VALUES(urgency),title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status=VALUES(source_status),requires_action=VALUES(requires_action),due_at=VALUES(due_at),occurred_at=VALUES(occurred_at),read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint) AND VALUES(requires_action)=1,NULL,resolved_at),snoozed_until=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,snoozed_until),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";
        $this->pdo->prepare($sql)->execute([$userId,$tripId,$sourceType,$sourceKey,$threadKey,$itemType,$priority,$urgency,$title,$body,$actionLabel,$actionUrl,$sourceStatus,$requiresAction?1:0,$fingerprint,$dueAt,$occurredAt]);
    }

    private function autoResolveMissing(int $userId,?int $tripId,string $sourceType,array $seen): void
    {
        if(!in_array($sourceType,self::ACTIONABLE_SOURCES,true))return;$params=[$userId,$sourceType];$tripSql=$tripId===null?'dream_trip_id IS NULL':'dream_trip_id=?';if($tripId!==null)$params[]=$tripId;$sql="UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),source_status='resolved',updated_at=NOW() WHERE user_id=? AND source_type=? AND {$tripSql} AND requires_action=1 AND resolved_at IS NULL";
        if($seen){$seen=array_values(array_unique(array_map('strval',$seen)));$sql.=' AND source_key NOT IN ('.implode(',',array_fill(0,count($seen),'?')).')';$params=array_merge($params,$seen);}$this->pdo->prepare($sql)->execute($params);
    }

    private function assertInboxTripAccess(int $userId,int $tripId): void
    {
        foreach($this->accessibleTrips($userId) as $trip)if((int)$trip['id']===$tripId)return;throw new OutOfBoundsException('Trip not found or permission denied.');
    }

    private function castItem(array $row): array
    {
        foreach(['id','user_id'] as $k)$row[$k]=(int)$row[$k];$row['dream_trip_id']=$row['dream_trip_id']!==null?(int)$row['dream_trip_id']:null;$row['priority']=(int)$row['priority'];$row['requires_action']=!empty($row['requires_action']);$row['trip_muted']=!empty($row['trip_muted']);return $row;
    }

    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Unified Trip Inbox.');}
    private function clip(string $value,int $limit): string{$value=trim($value);return function_exists('mb_substr')?mb_substr($value,0,$limit):substr($value,0,$limit);}
}
