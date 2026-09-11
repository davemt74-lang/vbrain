<?php
declare(strict_types=1);

final class TripAgentAutomationService
{
    private const MAX_DAILY_BATCHES=6;
    private const MAX_ATTEMPTS=5;
    private const MAX_GROUP_EVENTS=20;
    private const STALE_CLAIM_MINUTES=10;
    private const ACTIVE_DEFER_MINUTES=5;
    private const CAP_DEFER_MINUTES=240;
    private const DEBOUNCE_SECONDS=45;

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_agent_automation_triggers')
            && db_table_exists('travel_watch_events')
            && db_table_exists('travel_watches')
            && (new TripAgentJobService($this->pdo))->ready();
    }

    public function runDue(int $groupLimit=5): array
    {
        $this->requireReady();$groupLimit=max(1,min(10,$groupLimit));$captured=$this->syncWatchEvents(250);$suppressed=$this->suppressInactive();$recovered=$this->recoverStaleClaims();
        $result=['captured'=>$captured,'suppressed'=>$suppressed,'recovered'=>$recovered,'groups'=>0,'dispatched'=>0,'deferred'=>0,'failed'=>0,'batches'=>[]];
        for($i=0;$i<$groupLimit;$i++){
            $claim=$this->claimNextGroup();if(!$claim)break;$result['groups']++;
            try{
                $out=$this->dispatchClaim($claim);$status=(string)($out['status']??'deferred');if(isset($result[$status]))$result[$status]++;if(!empty($out['batch_id']))$result['batches'][]=(int)$out['batch_id'];
            }catch(Throwable $e){$failed=$this->failClaim((string)$claim['token'],$claim,$e->getMessage());$result[$failed?'failed':'deferred']++;}
        }
        $result['batches']=array_values(array_unique($result['batches']));return $result;
    }

    public function activityState(int $userId,?int $tripId=null): array
    {
        $out=['ready'=>$this->ready(),'pending_total'=>0,'dispatching_total'=>0,'by_agent'=>[],'last_at'=>null];if(!$out['ready'])return $out;
        $where="user_id=? AND status IN ('pending','deferred','dispatching')";$params=[$userId];if($tripId!==null&&$tripId>0){$where.=' AND dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT agent_type,status,COUNT(*) total,MAX(updated_at) last_at FROM trip_agent_automation_triggers WHERE $where GROUP BY agent_type,status");$stmt->execute($params);
        foreach($stmt->fetchAll()?:[] as $row){$agent=(string)$row['agent_type'];$status=(string)$row['status'];$count=(int)$row['total'];if(!isset($out['by_agent'][$agent]))$out['by_agent'][$agent]=['pending'=>0,'deferred'=>0,'dispatching'=>0];$out['by_agent'][$agent][$status]=$count;if($status==='dispatching')$out['dispatching_total']+=$count;else$out['pending_total']+=$count;$at=(string)($row['last_at']??'');if($at!==''&&($out['last_at']===null||$at>$out['last_at']))$out['last_at']=$at;}
        return $out;
    }

    public function syncWatchEvents(int $limit=250): int
    {
        $this->requireReady();$limit=max(1,min(1000,$limit));$delay=self::DEBOUNCE_SECONDS;
        $sql="INSERT IGNORE INTO trip_agent_automation_triggers (user_id,dream_trip_id,travel_watch_event_id,data_type,agent_type,status,next_attempt_at)
              SELECT e.user_id,w.dream_trip_id,e.id,e.data_type,
                     CASE e.data_type WHEN 'weather' THEN 'weather' WHEN 'flights' THEN 'flights' WHEN 'events' THEN 'events' WHEN 'places' THEN 'local' END,
                     'pending',DATE_ADD(NOW(),INTERVAL $delay SECOND)
              FROM travel_watch_events e
              JOIN travel_watches w ON w.id=e.watch_id AND w.user_id=e.user_id
              JOIN dream_trips d ON d.id=w.dream_trip_id AND d.user_id=e.user_id
              LEFT JOIN trip_agent_automation_triggers existing ON existing.travel_watch_event_id=e.id
              WHERE existing.id IS NULL
                AND w.target_type='trip' AND w.is_active=1 AND w.dream_trip_id IS NOT NULL
                AND e.notified_at IS NOT NULL AND e.data_type IN ('weather','flights','events','places')
                AND d.status<>'abandoned' AND (d.end_date IS NULL OR d.end_date>=CURDATE())
                AND e.created_at>=COALESCE((SELECT CAST(meta_value AS DATETIME) FROM app_meta WHERE meta_key='trip_agent_automation_started_at' LIMIT 1),NOW())
              ORDER BY e.id ASC LIMIT $limit";
        $count=$this->pdo->exec($sql);return $count===false?0:(int)$count;
    }

    public function suppressInactive(): int
    {
        if(!$this->ready())return 0;$sql="UPDATE trip_agent_automation_triggers t
            LEFT JOIN travel_watch_events e ON e.id=t.travel_watch_event_id
            LEFT JOIN travel_watches w ON w.id=e.watch_id
            LEFT JOIN dream_trips d ON d.id=t.dream_trip_id AND d.user_id=t.user_id
            SET t.status='suppressed',t.claim_token=NULL,t.error_message='Watch or trip is no longer active.'
            WHERE t.status IN ('pending','deferred') AND (w.id IS NULL OR w.is_active<>1 OR d.id IS NULL OR d.status='abandoned' OR (d.end_date IS NOT NULL AND d.end_date<CURDATE()))";
        $count=$this->pdo->exec($sql);return $count===false?0:(int)$count;
    }

    public function recoverStaleClaims(): int
    {
        if(!$this->ready())return 0;$cutoff=(new DateTimeImmutable('-'.self::STALE_CLAIM_MINUTES.' minutes'))->format('Y-m-d H:i:s');$stmt=$this->pdo->prepare("SELECT DISTINCT claim_token FROM trip_agent_automation_triggers WHERE status='dispatching' AND claim_token IS NOT NULL AND updated_at<? LIMIT 50");$stmt->execute([$cutoff]);$tokens=array_values(array_filter(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[])));$count=0;
        foreach($tokens as $token){$claim=$this->loadClaim($token);if(!$claim)continue;$this->failClaim($token,$claim,'Automation dispatcher stopped before the agent batch was created.');$count++;}return $count;
    }

    private function claimNextGroup(): ?array
    {
        $seed=(int)($this->pdo->query("SELECT id FROM trip_agent_automation_triggers WHERE status IN ('pending','deferred') AND next_attempt_at<=NOW() ORDER BY next_attempt_at ASC,id ASC LIMIT 1")->fetchColumn()?:0);if($seed<1)return null;$token=bin2hex(random_bytes(16));
        try{
            $this->pdo->beginTransaction();$seedStmt=$this->pdo->prepare("SELECT user_id,dream_trip_id FROM trip_agent_automation_triggers WHERE id=? AND status IN ('pending','deferred') AND next_attempt_at<=NOW() FOR UPDATE");$seedStmt->execute([$seed]);$seedRow=$seedStmt->fetch();if(!$seedRow){$this->pdo->rollBack();return null;}$userId=(int)$seedRow['user_id'];$tripId=(int)$seedRow['dream_trip_id'];$limit=self::MAX_GROUP_EVENTS;
            $rows=$this->pdo->prepare("SELECT id FROM trip_agent_automation_triggers WHERE user_id=? AND dream_trip_id=? AND status IN ('pending','deferred') AND next_attempt_at<=NOW() ORDER BY id ASC LIMIT $limit FOR UPDATE");$rows->execute([$userId,$tripId]);$ids=array_map('intval',$rows->fetchAll(PDO::FETCH_COLUMN)?:[]);if(!$ids){$this->pdo->rollBack();return null;}$placeholders=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([$token],$ids);$update=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='dispatching',claim_token=?,error_message=NULL WHERE id IN ($placeholders) AND status IN ('pending','deferred')");$update->execute($params);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->loadClaim($token);
    }

    private function loadClaim(string $token): ?array
    {
        if($token==='')return null;$stmt=$this->pdo->prepare("SELECT t.*,e.event_type,e.direction,e.title,e.body,e.created_at event_created_at,w.destination_name,w.is_active watch_active
            FROM trip_agent_automation_triggers t
            JOIN travel_watch_events e ON e.id=t.travel_watch_event_id
            JOIN travel_watches w ON w.id=e.watch_id
            WHERE t.claim_token=? AND t.status='dispatching' ORDER BY t.id ASC");$stmt->execute([$token]);$rows=$stmt->fetchAll()?:[];if(!$rows)return null;return ['token'=>$token,'user_id'=>(int)$rows[0]['user_id'],'trip_id'=>(int)$rows[0]['dream_trip_id'],'rows'=>$rows];
    }

    private function dispatchClaim(array $claim): array
    {
        $token=(string)$claim['token'];$userId=(int)$claim['user_id'];$tripId=(int)$claim['trip_id'];$rows=is_array($claim['rows']??null)?$claim['rows']:[];if(!$rows)throw new RuntimeException('Automation trigger claim is empty.');
        foreach($rows as $row){if(empty($row['watch_active'])){$this->suppressClaim($token,'Watch is no longer active.');return ['status'=>'suppressed'];}}
        if($this->dailyBatchCount($userId)>=self::MAX_DAILY_BATCHES){$this->deferClaim($token,self::CAP_DEFER_MINUTES,'Daily proactive-agent limit reached; the watch follow-up will retry later.');return ['status'=>'deferred'];}
        $jobs=new TripAgentJobService($this->pdo);if($jobs->hasActiveTripJobs($userId,$tripId)){$this->deferClaim($token,self::ACTIVE_DEFER_MINUTES,'Trip agents are already working; watch follow-up deferred.');return ['status'=>'deferred'];}
        $agents=[];$alertsByAgent=[];$allAlerts=[];
        foreach($rows as $row){$agent=(string)$row['agent_type'];if(!in_array($agent,['weather','flights','events','local'],true))continue;$agents[$agent]=true;$line=$this->alertLine($row);$alertsByAgent[$agent][]=$line;$allAlerts[]=$line;}
        if(!$agents){$this->suppressClaim($token,'No supported specialist agent was mapped to these watch changes.');return ['status'=>'suppressed'];}
        $agentList=array_keys($agents);$agentList[]='overview';$requests=[];
        foreach(array_keys($agents) as $agent){$requests[$agent]="A saved trip watch detected a meaningful change. Analyze the alert against the coherent shared trip-intelligence snapshot created for this automation. Explain whether it materially changes this trip and give the best next action. Do not invent live data or booking availability.\n\nWATCH ALERTS\n".$this->clip(implode("\n",$alertsByAgent[$agent]??[]),2200);}
        $requests['overview']="A saved trip watch detected meaningful changes. After the triggered specialist agents finish, synthesize their results using the same shared provider snapshot. Explain what changed, why it matters for this trip, whether the user should act now, and the top three next actions in priority order. Do not invent live data or booking inventory.\n\nWATCH ALERTS\n".$this->clip(implode("\n",$allAlerts),2200);
        try{$queued=$jobs->enqueueAgentSet($userId,$tripId,$agentList,'automation',$requests);}catch(DomainException $e){$this->deferClaim($token,self::ACTIVE_DEFER_MINUTES,$this->clip($e->getMessage(),500));return ['status'=>'deferred'];}
        $batchId=0;foreach($queued as $job){$batchId=max($batchId,(int)($job['batch_id']??0));}if($batchId<1)throw new RuntimeException('Proactive agent batch was not created.');
        $stmt=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='dispatched',claim_token=NULL,batch_id=?,error_message=NULL,dispatched_at=NOW() WHERE claim_token=? AND status='dispatching'");$stmt->execute([$batchId,$token]);return ['status'=>'dispatched','batch_id'=>$batchId,'agents'=>$agentList,'trigger_count'=>count($rows)];
    }

    private function dailyBatchCount(int $userId): int
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(DISTINCT batch_id) FROM trip_agent_automation_triggers WHERE user_id=? AND status='dispatched' AND batch_id IS NOT NULL AND dispatched_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)");$stmt->execute([$userId]);return (int)$stmt->fetchColumn();
    }

    private function deferClaim(string $token,int $minutes,string $reason): void
    {
        $minutes=max(1,min(1440,$minutes));$stmt=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='deferred',claim_token=NULL,next_attempt_at=DATE_ADD(NOW(),INTERVAL $minutes MINUTE),error_message=? WHERE claim_token=? AND status='dispatching'");$stmt->execute([$this->clip($reason,1000),$token]);
    }

    private function suppressClaim(string $token,string $reason): void
    {
        $stmt=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='suppressed',claim_token=NULL,error_message=? WHERE claim_token=? AND status='dispatching'");$stmt->execute([$this->clip($reason,1000),$token]);
    }

    private function failClaim(string $token,array $claim,string $message): bool
    {
        $rows=is_array($claim['rows']??null)?$claim['rows']:[];$maxAttempt=0;foreach($rows as $row)$maxAttempt=max($maxAttempt,(int)($row['attempt_count']??0));$nextAttempt=$maxAttempt+1;$failed=$nextAttempt>=self::MAX_ATTEMPTS;$delay=min(120,10*$nextAttempt);$message=$this->clip($message,1000);
        if($failed){$stmt=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='failed',claim_token=NULL,attempt_count=attempt_count+1,error_message=? WHERE claim_token=? AND status='dispatching'");$stmt->execute([$message,$token]);$this->notifyAutomationFailure((int)$claim['user_id'],(int)$claim['trip_id'],$message);}
        else{$stmt=$this->pdo->prepare("UPDATE trip_agent_automation_triggers SET status='deferred',claim_token=NULL,attempt_count=attempt_count+1,next_attempt_at=DATE_ADD(NOW(),INTERVAL $delay MINUTE),error_message=? WHERE claim_token=? AND status='dispatching'");$stmt->execute([$message,$token]);}
        return $failed;
    }

    private function notifyAutomationFailure(int $userId,int $tripId,string $message): void
    {
        try{(new NotificationService($this->pdo))->create($userId,'trip_agent_automation','Vacation Brain could not finish a watch follow-up','Your watch alert is still saved, but the automatic agent review could not be queued. '.$this->clip($message,240),app_url('dream-trip.php?id='.$tripId.'#agent-results'),null,null,'trip-agent-automation:'.$tripId.':'.date('Y-m-d'));}catch(Throwable $e){}
    }

    private function alertLine(array $row): string
    {
        $title=$this->clip((string)($row['title']??'Trip watch changed'),180);$body=$this->clip((string)($row['body']??''),500);return '- '.$title.($body!==''?': '.$body:'');
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before using proactive trip-agent automation.');
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}