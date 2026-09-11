<?php
declare(strict_types=1);

final class TripAgentJobService
{
    private const AGENTS=['overview','weather','flights','events','local','itinerary','budget'];
    private const RUN_ALL_ORDER=['weather','flights','events','local','itinerary','budget','overview'];
    private const MAX_ACTIVE_JOBS=20;
    private const STALE_MINUTES=20;
    private const MAX_ATTEMPTS=2;

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_agent_jobs') && (new TripAgentBatchService($this->pdo))->ready();
    }

    public function enqueue(int $userId,int $tripId,string $agentType,string $request=''): array
    {
        $this->requireReady();$agentType=$this->agent($agentType);$this->assertTrip($userId,$tripId);$request=trim($request);if($request==='')$request=$this->defaultRequest($agentType);
        if((function_exists('mb_strlen')?mb_strlen($request):strlen($request))>3000)throw new InvalidArgumentException('Agent task is too long.');
        $activeKey=$this->activeKey($tripId,$agentType);$existing=$this->activeByKey($userId,$activeKey);if($existing)return $this->publicJob($existing);$this->assertCapacity($userId,1);
        $batchService=new TripAgentBatchService($this->pdo);$batch=$batchService->create($userId,$tripId,[$agentType],'single');$batchId=(int)($batch['id']??0);
        try{
            $stmt=$this->pdo->prepare("INSERT INTO trip_agent_jobs (user_id,dream_trip_id,batch_id,agent_type,request_text,status,progress,status_text,active_key) VALUES (?,?,?,?,?,'queued',0,'Preparing shared trip intelligence',?)");$stmt->execute([$userId,$tripId,$batchId,$agentType,$request,$activeKey]);return $this->jobForUser($userId,(int)$this->pdo->lastInsertId())??[];
        }catch(PDOException $e){$existing=$this->activeByKey($userId,$activeKey);$batchService->deleteIfUnused($batchId);if($existing)return $this->publicJob($existing);throw $e;}
    }

    public function enqueueAll(int $userId,int $tripId): array
    {
        $this->requireReady();$this->assertTrip($userId,$tripId);if($this->activeTripCount($userId,$tripId)>0)throw new DomainException('Trip agents are already working. Let the current batch finish before running all agents again.');$this->assertCapacity($userId,count(self::RUN_ALL_ORDER));
        $batchService=new TripAgentBatchService($this->pdo);$batch=$batchService->create($userId,$tripId,self::RUN_ALL_ORDER,'all');$batchId=(int)($batch['id']??0);$jobs=[];
        try{
            $this->pdo->beginTransaction();$lock=$this->pdo->prepare('SELECT id FROM dream_trips WHERE id=? AND user_id=? FOR UPDATE');$lock->execute([$tripId,$userId]);if(!$lock->fetchColumn())throw new OutOfBoundsException('Trip not found.');if($this->activeTripCount($userId,$tripId)>0)throw new DomainException('Trip agents are already working. Let the current batch finish before running all agents again.');$this->assertCapacity($userId,count(self::RUN_ALL_ORDER));
            foreach(self::RUN_ALL_ORDER as $agent){$stmt=$this->pdo->prepare("INSERT INTO trip_agent_jobs (user_id,dream_trip_id,batch_id,agent_type,request_text,status,progress,status_text,active_key) VALUES (?,?,?,?,?,'queued',0,'Preparing shared trip intelligence',?)");$stmt->execute([$userId,$tripId,$batchId,$agent,$this->defaultRequest($agent),$this->activeKey($tripId,$agent)]);$jobs[]=(int)$this->pdo->lastInsertId();}
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();$batchService->deleteIfUnused($batchId);throw $e;}
        return array_values(array_filter(array_map(fn(int $id)=>$this->jobForUser($userId,$id),$jobs)));
    }

    public function cancel(int $userId,int $jobId): array
    {
        $this->requireReady();$job=$this->rawJob($jobId);if(!$job||(int)$job['user_id']!==$userId)throw new OutOfBoundsException('Agent job not found.');
        if($job['status']==='queued'){$this->pdo->prepare("UPDATE trip_agent_jobs SET status='cancelled',progress=100,status_text='Cancelled',active_key=NULL,worker_token=NULL,completed_at=NOW() WHERE id=? AND user_id=? AND status='queued'")->execute([$jobId,$userId]);}
        elseif($job['status']==='running')throw new DomainException('This agent is already working and cannot be interrupted safely.');
        return $this->jobForUser($userId,$jobId)??[];
    }

    public function jobsForTrip(int $userId,int $tripId,int $limit=40): array
    {
        $this->requireReady();$this->assertTrip($userId,$tripId);$limit=max(1,min(100,$limit));$stmt=$this->pdo->prepare("SELECT j.*,b.status batch_status,b.ready_at batch_ready_at FROM trip_agent_jobs j LEFT JOIN trip_agent_batches b ON b.id=j.batch_id WHERE j.user_id=? AND j.dream_trip_id=? ORDER BY CASE j.status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,j.updated_at DESC,j.id DESC LIMIT $limit");$stmt->execute([$userId,$tripId]);return array_map(fn(array $row)=>$this->publicJob($row),$stmt->fetchAll()?:[]);
    }

    public function activityStates(int $userId,?int $tripId=null): array
    {
        $out=['ready'=>$this->ready(),'active_total'=>0,'by_agent'=>[],'recent'=>[]];if(!$out['ready'])return $out;
        $where="j.user_id=? AND (j.status IN ('queued','running') OR j.updated_at>=DATE_SUB(NOW(),INTERVAL 1 DAY))";$params=[$userId];if($tripId!==null&&$tripId>0){$where.=' AND j.dream_trip_id=?';$params[]=$tripId;}
        $stmt=$this->pdo->prepare("SELECT j.*,b.status batch_status,b.ready_at batch_ready_at FROM trip_agent_jobs j LEFT JOIN trip_agent_batches b ON b.id=j.batch_id WHERE $where ORDER BY CASE j.status WHEN 'running' THEN 0 WHEN 'queued' THEN 1 ELSE 2 END,j.updated_at DESC,j.id DESC LIMIT 160");$stmt->execute($params);$rows=$stmt->fetchAll()?:[];
        foreach($rows as $row){
            $agent=(string)$row['agent_type'];$status=(string)$row['status'];if(in_array($status,['queued','running'],true))$out['active_total']++;
            if(!isset($out['by_agent'][$agent]))$out['by_agent'][$agent]=['queued'=>0,'running'=>0,'completed'=>0,'failed'=>0,'cancelled'=>0,'last_status'=>$status,'last_progress'=>(int)$row['progress'],'last_at'=>(string)$row['updated_at'],'job_id'=>(int)$row['id'],'batch_id'=>(int)($row['batch_id']??0),'batch_status'=>(string)($row['batch_status']??'')];
            if(isset($out['by_agent'][$agent][$status]))$out['by_agent'][$agent][$status]++;if(count($out['recent'])<20)$out['recent'][]=$this->publicJob($row);
        }
        return $out;
    }

    public function runDue(int $limit=5): array
    {
        $this->requireReady();$limit=max(1,min(20,$limit));$batchResult=(new TripAgentBatchService($this->pdo))->prepareDue(min(3,$limit));$this->recoverStale();$result=['batches'=>$batchResult,'claimed'=>0,'completed'=>0,'failed'=>0,'retried'=>0,'jobs'=>[]];
        for($i=0;$i<$limit;$i++){
            $sql="SELECT j.id FROM trip_agent_jobs j LEFT JOIN trip_agent_batches b ON b.id=j.batch_id WHERE j.status='queued' AND (j.batch_id IS NULL OR b.status='ready') AND (j.agent_type<>'overview' OR j.batch_id IS NULL OR NOT EXISTS (SELECT 1 FROM trip_agent_jobs s WHERE s.batch_id=j.batch_id AND s.agent_type<>'overview' AND s.status IN ('queued','running'))) ORDER BY j.queued_at ASC,j.id ASC LIMIT 1";$id=(int)($this->pdo->query($sql)->fetchColumn()?:0);if($id<1)break;
            $token=bin2hex(random_bytes(16));$claim=$this->pdo->prepare("UPDATE trip_agent_jobs SET status='running',progress=15,status_text='Agent is working from the shared snapshot',worker_token=?,attempt_count=attempt_count+1,started_at=NOW(),heartbeat_at=NOW(),error_message=NULL WHERE id=? AND status='queued'");$claim->execute([$token,$id]);if($claim->rowCount()!==1){$i--;continue;}$result['claimed']++;$job=$this->rawJob($id);if(!$job)continue;
            try{
                $this->pdo->prepare("UPDATE trip_agent_jobs SET progress=35,status_text='Reading coherent trip intelligence',heartbeat_at=NOW() WHERE id=? AND worker_token=? AND status='running'")->execute([$id,$token]);$dashboard=(new TripAgentBatchService($this->pdo))->dashboardForJob($job);
                $reply=(new TripAgentService($this->pdo))->send((int)$job['user_id'],(int)$job['dream_trip_id'],(string)$job['agent_type'],(string)$job['request_text'],'trip-agent-job:'.$id,$dashboard,(int)($job['batch_id']??0)?:null);
                $json=json_encode($reply,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($json===false)$json='{}';$done=$this->pdo->prepare("UPDATE trip_agent_jobs SET status='completed',progress=100,status_text='Complete',active_key=NULL,worker_token=NULL,result_json=?,error_message=NULL,heartbeat_at=NOW(),completed_at=NOW() WHERE id=? AND worker_token=? AND status='running'");$done->execute([$json,$id,$token]);$result['completed']++;$result['jobs'][]=['id'=>$id,'status'=>'completed','agent_type'=>(string)$job['agent_type'],'batch_id'=>(int)($job['batch_id']??0)];$this->notifyComplete($job,$id,false,null);
            }catch(Throwable $e){$retry=$this->markFailure($job,$token,$e->getMessage());$result[$retry?'retried':'failed']++;$result['jobs'][]=['id'=>$id,'status'=>$retry?'queued':'failed','agent_type'=>(string)$job['agent_type'],'batch_id'=>(int)($job['batch_id']??0),'error'=>$this->clip($e->getMessage(),300)];if(!$retry)$this->notifyComplete($job,$id,true,$e->getMessage());}
        }
        return $result;
    }

    public function recoverStale(): int
    {
        if(!$this->ready())return 0;$cutoff=(new DateTimeImmutable('-'.self::STALE_MINUTES.' minutes'))->format('Y-m-d H:i:s');$stmt=$this->pdo->prepare("SELECT * FROM trip_agent_jobs WHERE status='running' AND COALESCE(heartbeat_at,started_at,updated_at)<? ORDER BY id ASC LIMIT 100");$stmt->execute([$cutoff]);$rows=$stmt->fetchAll()?:[];$count=0;
        foreach($rows as $job){
            if((int)$job['attempt_count']<self::MAX_ATTEMPTS){$this->pdo->prepare("UPDATE trip_agent_jobs SET status='queued',progress=0,status_text='Recovered after worker interruption',worker_token=NULL,started_at=NULL,heartbeat_at=NULL,queued_at=NOW(),error_message='Previous worker stopped before completion.' WHERE id=? AND status='running'")->execute([(int)$job['id']]);}
            else{$this->pdo->prepare("UPDATE trip_agent_jobs SET status='failed',progress=100,status_text='Failed after worker interruption',active_key=NULL,worker_token=NULL,completed_at=NOW(),error_message='Agent worker stopped before completion.' WHERE id=? AND status='running'")->execute([(int)$job['id']]);$this->notifyComplete($job,(int)$job['id'],true,'Agent worker stopped before completion.');}
            $count++;
        }
        return $count;
    }

    public function jobForUser(int $userId,int $jobId): ?array
    {
        if(!$this->ready()||$jobId<1)return null;$stmt=$this->pdo->prepare('SELECT j.*,b.status batch_status,b.ready_at batch_ready_at FROM trip_agent_jobs j LEFT JOIN trip_agent_batches b ON b.id=j.batch_id WHERE j.id=? AND j.user_id=? LIMIT 1');$stmt->execute([$jobId,$userId]);$job=$stmt->fetch();return $job?$this->publicJob($job):null;
    }

    private function markFailure(array $job,string $token,string $message): bool
    {
        $id=(int)$job['id'];$attempt=(int)$job['attempt_count'];$message=$this->clip($message,1000);
        if($attempt<self::MAX_ATTEMPTS){$stmt=$this->pdo->prepare("UPDATE trip_agent_jobs SET status='queued',progress=0,status_text='Retry queued',worker_token=NULL,started_at=NULL,heartbeat_at=NULL,queued_at=NOW(),error_message=? WHERE id=? AND worker_token=? AND status='running'");$stmt->execute([$message,$id,$token]);return true;}
        $stmt=$this->pdo->prepare("UPDATE trip_agent_jobs SET status='failed',progress=100,status_text='Agent failed',active_key=NULL,worker_token=NULL,error_message=?,heartbeat_at=NOW(),completed_at=NOW() WHERE id=? AND worker_token=? AND status='running'");$stmt->execute([$message,$id,$token]);return false;
    }

    private function notifyComplete(array $job,int $jobId,bool $failed,?string $error): void
    {
        try{$agent=$this->label((string)$job['agent_type']);$tripId=(int)$job['dream_trip_id'];$url=app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode((string)$job['agent_type']).'#agent-results');(new NotificationService($this->pdo))->create((int)$job['user_id'],'trip_agent_job',$failed?$agent.' needs attention':$agent.' finished',$failed?'Vacation Brain could not finish this task. '.$this->clip((string)$error,300):'New trip-agent results are ready to review.',$url,null,null,'trip-agent-job:'.$jobId.':'.($failed?'failed':'completed'));}catch(Throwable $e){}
    }

    private function assertCapacity(int $userId,int $additional): void
    {
        if($additional<=0)return;$stmt=$this->pdo->prepare("SELECT COUNT(*) FROM trip_agent_jobs WHERE user_id=? AND status IN ('queued','running')");$stmt->execute([$userId]);$count=(int)$stmt->fetchColumn();if($count+$additional>self::MAX_ACTIVE_JOBS)throw new DomainException('Too many agent jobs are already queued. Let the current work finish first.');
    }

    private function activeTripCount(int $userId,int $tripId): int
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM trip_agent_jobs WHERE user_id=? AND dream_trip_id=? AND status IN ('queued','running')");$stmt->execute([$userId,$tripId]);return (int)$stmt->fetchColumn();
    }

    private function activeByKey(int $userId,string $activeKey): ?array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM trip_agent_jobs WHERE user_id=? AND active_key=? AND status IN ('queued','running') LIMIT 1");$stmt->execute([$userId,$activeKey]);return $stmt->fetch()?:null;
    }

    private function rawJob(int $id): ?array
    {
        if(!$this->ready()||$id<1)return null;$stmt=$this->pdo->prepare('SELECT * FROM trip_agent_jobs WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch()?:null;
    }

    private function publicJob(array $row): array
    {
        return ['id'=>(int)$row['id'],'trip_id'=>(int)$row['dream_trip_id'],'batch_id'=>(int)($row['batch_id']??0)?:null,'batch_status'=>(string)($row['batch_status']??''),'batch_ready_at'=>$row['batch_ready_at']??null,'agent_type'=>(string)$row['agent_type'],'agent_label'=>$this->label((string)$row['agent_type']),'status'=>(string)$row['status'],'progress'=>(int)$row['progress'],'status_text'=>(string)($row['status_text']??''),'attempt_count'=>(int)$row['attempt_count'],'request_text'=>$this->clip((string)$row['request_text'],300),'error'=>(string)($row['error_message']??''),'queued_at'=>(string)$row['queued_at'],'started_at'=>$row['started_at']??null,'heartbeat_at'=>$row['heartbeat_at']??null,'completed_at'=>$row['completed_at']??null,'updated_at'=>(string)$row['updated_at']];
    }

    private function assertTrip(int $userId,int $tripId): array
    {
        if($tripId<1)throw new InvalidArgumentException('Trip is required.');$trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new OutOfBoundsException('Trip not found.');return $trip;
    }

    private function agent(string $value): string
    {
        $value=strtolower(trim($value));if(!in_array($value,self::AGENTS,true))throw new InvalidArgumentException('Unknown trip agent.');return $value;
    }

    private function activeKey(int $tripId,string $agent): string{return 'trip:'.$tripId.':agent:'.$agent;}

    private function label(string $agent): string
    {
        return match($agent){'overview'=>'Overview Agent','weather'=>'Weather Agent','flights'=>'Flights Agent','events'=>'Events Agent','local'=>'Local Agent','itinerary'=>'Itinerary Agent','budget'=>'Budget Agent',default=>'Trip Agent'};
    }

    private function defaultRequest(string $agent): string
    {
        return match($agent){
            'weather'=>'Review the latest weather intelligence for this trip. Explain what changed, what affects the dates or itinerary, and what I should do next.',
            'flights'=>'Review the latest flight intelligence for this trip. Flag meaningful fare or routing changes and tell me the best next planning action without inventing bookable inventory.',
            'events'=>'Review current events intelligence for this trip. Surface the strongest date-specific opportunities and any schedule conflicts or weather-fit issues.',
            'local'=>'Review current local-place intelligence for this trip. Find the strongest restaurants, activities, and local opportunities that fit the trip and explain what deserves a saved itinerary slot.',
            'itinerary'=>'Review the full trip and improve the itinerary. Identify gaps, timing problems, weather conflicts, and the most useful next scheduling changes.',
            'budget'=>'Review the trip budget, flight intelligence, and saved items. Explain where the budget stands, major cost risks, and the next decision that improves value.',
            default=>'Review the entire trip as the supervising agent. Summarize the completed specialist results from this shared intelligence batch, identify the most important opportunity or risk, and give me the next three planning actions in priority order.',
        };
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before using live agent jobs.');
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
