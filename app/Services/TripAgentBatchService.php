<?php
declare(strict_types=1);

final class TripAgentBatchService
{
    private const DATA_TYPES=['weather','flights','events','places'];
    private const MAX_ATTEMPTS=2;
    private const STALE_MINUTES=20;

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_agent_batches') && db_table_exists('trip_agent_jobs') && db_column_exists('trip_agent_jobs','batch_id');
    }

    public function create(int $userId,int $tripId,array $agentTypes,string $scope='single'): array
    {
        $this->requireReady();$this->assertTrip($userId,$tripId);$agents=$this->agents($agentTypes);if(!$agents)throw new InvalidArgumentException('At least one trip agent is required.');
        $scope=in_array($scope,['single','all','automation'],true)?$scope:'single';$required=$this->requiredTypes($agents,$scope);
        $stmt=$this->pdo->prepare("INSERT INTO trip_agent_batches (user_id,dream_trip_id,scope,agent_types_json,required_types_json,status) VALUES (?,?,?,?,?,'queued')");
        $stmt->execute([$userId,$tripId,$scope,$this->json($agents),$this->json($required)]);
        return $this->batchForUser($userId,(int)$this->pdo->lastInsertId())??[];
    }

    public function prepareDue(int $limit=2): array
    {
        $this->requireReady();$limit=max(1,min(5,$limit));$this->recoverStale();$result=['claimed'=>0,'ready'=>0,'failed'=>0,'retried'=>0,'batches'=>[]];
        for($i=0;$i<$limit;$i++){
            $id=(int)($this->pdo->query("SELECT b.id FROM trip_agent_batches b WHERE b.status='queued' AND EXISTS (SELECT 1 FROM trip_agent_jobs j WHERE j.batch_id=b.id AND j.status='queued') ORDER BY b.queued_at ASC,b.id ASC LIMIT 1")->fetchColumn()?:0);if($id<1)break;
            $token=bin2hex(random_bytes(16));$claim=$this->pdo->prepare("UPDATE trip_agent_batches SET status='refreshing',worker_token=?,attempt_count=attempt_count+1,started_at=NOW(),heartbeat_at=NOW(),error_message=NULL WHERE id=? AND status='queued'");$claim->execute([$token,$id]);if($claim->rowCount()!==1){$i--;continue;}
            $result['claimed']++;$batch=$this->rawBatch($id);if(!$batch)continue;
            try{$status=$this->prepareClaimed($batch,$token);if($status==='ready')$result['ready']++;$result['batches'][]=['id'=>$id,'status'=>$status];}
            catch(Throwable $e){$retry=$this->markFailure($batch,$token,$e->getMessage());$result[$retry?'retried':'failed']++;$result['batches'][]=['id'=>$id,'status'=>$retry?'queued':'failed','error'=>$this->clip($e->getMessage(),300)];}
        }
        return $result;
    }

    public function dashboardForJob(array $job): ?array
    {
        $batchId=(int)($job['batch_id']??0);if($batchId<1)return null;$batch=$this->rawBatch($batchId);if(!$batch||(int)$batch['user_id']!==(int)$job['user_id']||(int)$batch['dream_trip_id']!==(int)$job['dream_trip_id'])throw new RuntimeException('Agent intelligence batch is invalid.');
        if((string)$batch['status']!=='ready')throw new RuntimeException('Agent intelligence batch is not ready.');$dashboard=json_decode((string)($batch['context_json']??''),true);if(!is_array($dashboard))throw new RuntimeException('Agent intelligence batch context is unavailable.');
        if((string)($job['agent_type']??'')==='overview')$dashboard['_agent_batch']['specialist_results']=$this->specialistResults($batchId);
        return $dashboard;
    }

    public function batchForUser(int $userId,int $batchId): ?array
    {
        $row=$this->rawBatch($batchId);return $row&&(int)$row['user_id']===$userId?$this->publicBatch($row):null;
    }

    public function deleteIfUnused(int $batchId): void
    {
        if(!$this->ready()||$batchId<1)return;$stmt=$this->pdo->prepare('DELETE b FROM trip_agent_batches b LEFT JOIN trip_agent_jobs j ON j.batch_id=b.id WHERE b.id=? AND j.id IS NULL');$stmt->execute([$batchId]);
    }

    public function cancelIfInactive(int $batchId): void
    {
        if(!$this->ready()||$batchId<1)return;$stmt=$this->pdo->prepare("SELECT COUNT(*) FROM trip_agent_jobs WHERE batch_id=? AND status IN ('queued','running')");$stmt->execute([$batchId]);if((int)$stmt->fetchColumn()>0)return;$this->pdo->prepare("UPDATE trip_agent_batches SET status='cancelled',worker_token=NULL,error_message=NULL,completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND status='queued'")->execute([$batchId]);
    }

    public function recoverStale(): int
    {
        if(!$this->ready())return 0;$cutoff=(new DateTimeImmutable('-'.self::STALE_MINUTES.' minutes'))->format('Y-m-d H:i:s');$stmt=$this->pdo->prepare("SELECT * FROM trip_agent_batches WHERE status='refreshing' AND COALESCE(heartbeat_at,started_at,updated_at)<? ORDER BY id ASC LIMIT 50");$stmt->execute([$cutoff]);$rows=$stmt->fetchAll()?:[];$count=0;
        foreach($rows as $batch){$id=(int)$batch['id'];if((int)$batch['attempt_count']<self::MAX_ATTEMPTS){$this->pdo->prepare("UPDATE trip_agent_batches SET status='queued',worker_token=NULL,started_at=NULL,heartbeat_at=NULL,queued_at=NOW(),error_message='Previous batch worker stopped before completion.' WHERE id=? AND status='refreshing'")->execute([$id]);}
            else{$this->pdo->prepare("UPDATE trip_agent_batches SET status='failed',worker_token=NULL,error_message='Batch worker stopped before completion.',completed_at=NOW() WHERE id=? AND status='refreshing'")->execute([$id]);$this->failQueuedJobs($id,'Shared trip intelligence could not be prepared.');$this->notifyFailure($batch,$id,'Batch worker stopped before completion.');}$count++;}
        return $count;
    }

    private function prepareClaimed(array $batch,string $token): string
    {
        $id=(int)$batch['id'];$userId=(int)$batch['user_id'];$tripId=(int)$batch['dream_trip_id'];$required=json_decode((string)$batch['required_types_json'],true);$required=is_array($required)?array_values(array_intersect(self::DATA_TYPES,$required)):self::DATA_TYPES;
        $service=new TripIntelligenceService($this->pdo);if(!$service->ready())throw new RuntimeException('Run System Upgrade before preparing trip intelligence.');$dashboard=$service->dashboard($userId,$tripId);$stale=is_array($dashboard['stale']??null)?$dashboard['stale']:[];$refresh=array_values(array_intersect($required,$stale));
        $this->pdo->prepare("UPDATE trip_agent_batches SET heartbeat_at=NOW() WHERE id=? AND worker_token=? AND status='refreshing'")->execute([$id,$token]);if($refresh)$service->refresh($userId,$tripId,$refresh,true);$dashboard=$service->dashboard($userId,$tripId);
        $snapshotIds=[];foreach(self::DATA_TYPES as $type){$snapshot=$dashboard['snapshots'][$type]??null;if(is_array($snapshot)&&!empty($snapshot['id']))$snapshotIds[$type]=(int)$snapshot['id'];}
        $live=new TripLiveIntelligenceService($this->pdo);$supervisor=(new TripSupervisorService($this->pdo))->overview($userId,$tripId,$dashboard);$agents=json_decode((string)$batch['agent_types_json'],true);$agents=is_array($agents)?$agents:[];
        $dashboard['_agent_batch']=['id'=>$id,'scope'=>(string)$batch['scope'],'agent_types'=>$agents,'required_types'=>$required,'refreshed_types'=>$refresh,'snapshot_ids'=>$snapshotIds,'prepared_at'=>gmdate('c'),'changes'=>$live->allChanges($userId,$tripId),'updates'=>$supervisor['updates']??[],'data_health'=>$supervisor['data_health']??[],'supervisor'=>$supervisor];
        $context=$this->json($dashboard);$active=$this->pdo->prepare("SELECT COUNT(*) FROM trip_agent_jobs WHERE batch_id=? AND status IN ('queued','running')");$active->execute([$id]);if((int)$active->fetchColumn()===0){$cancel=$this->pdo->prepare("UPDATE trip_agent_batches SET status='cancelled',worker_token=NULL,refreshed_types_json=?,snapshot_ids_json=?,context_json=?,heartbeat_at=NOW(),completed_at=NOW(),error_message=NULL WHERE id=? AND worker_token=? AND status='refreshing'");$cancel->execute([$this->json($refresh),$this->json($snapshotIds),$context,$id,$token]);return 'cancelled';}
        $done=$this->pdo->prepare("UPDATE trip_agent_batches SET status='ready',worker_token=NULL,refreshed_types_json=?,snapshot_ids_json=?,context_json=?,heartbeat_at=NOW(),ready_at=NOW(),completed_at=NOW(),error_message=NULL WHERE id=? AND worker_token=? AND status='refreshing'");$done->execute([$this->json($refresh),$this->json($snapshotIds),$context,$id,$token]);if($done->rowCount()!==1)throw new RuntimeException('Agent intelligence batch lost its worker claim.');return 'ready';
    }

    private function markFailure(array $batch,string $token,string $message): bool
    {
        $id=(int)$batch['id'];$attempt=(int)$batch['attempt_count'];$message=$this->clip($message,1000);
        if($attempt<self::MAX_ATTEMPTS){$stmt=$this->pdo->prepare("UPDATE trip_agent_batches SET status='queued',worker_token=NULL,started_at=NULL,heartbeat_at=NULL,queued_at=NOW(),error_message=? WHERE id=? AND worker_token=? AND status='refreshing'");$stmt->execute([$message,$id,$token]);return true;}
        $stmt=$this->pdo->prepare("UPDATE trip_agent_batches SET status='failed',worker_token=NULL,error_message=?,heartbeat_at=NOW(),completed_at=NOW() WHERE id=? AND worker_token=? AND status='refreshing'");$stmt->execute([$message,$id,$token]);$this->failQueuedJobs($id,'Shared trip intelligence could not be prepared.');$this->notifyFailure($batch,$id,$message);return false;
    }

    private function failQueuedJobs(int $batchId,string $message): void
    {
        $this->pdo->prepare("UPDATE trip_agent_jobs SET status='failed',progress=100,status_text='Intelligence batch failed',active_key=NULL,worker_token=NULL,error_message=?,completed_at=NOW() WHERE batch_id=? AND status='queued'")->execute([$this->clip($message,1000),$batchId]);
    }

    private function notifyFailure(array $batch,int $batchId,string $message): void
    {
        try{$tripId=(int)$batch['dream_trip_id'];(new NotificationService($this->pdo))->create((int)$batch['user_id'],'trip_agent_batch','Trip agents need fresh data','Vacation Brain could not prepare the shared intelligence snapshot. '.$this->clip($message,260),app_url('dream-trip.php?id='.$tripId.'#agent-results'),null,null,'trip-agent-batch:'.$batchId.':failed');}catch(Throwable $e){}
    }

    private function specialistResults(int $batchId): array
    {
        $stmt=$this->pdo->prepare("SELECT agent_type,status,result_json,error_message,completed_at FROM trip_agent_jobs WHERE batch_id=? AND agent_type<>'overview' ORDER BY id ASC");$stmt->execute([$batchId]);$out=[];foreach($stmt->fetchAll()?:[] as $row){$result=json_decode((string)($row['result_json']??''),true);$out[(string)$row['agent_type']]=['status'=>(string)$row['status'],'message'=>is_array($result)?$this->clip((string)($result['message']??''),2500):'','error'=>$this->clip((string)($row['error_message']??''),500),'completed_at'=>$row['completed_at']??null];}return $out;
    }

    private function requiredTypes(array $agents,string $scope='single'): array
    {
        $effective=$agents;if($scope==='automation'&&count($agents)>1){$withoutOverview=array_values(array_filter($agents,static fn(string $agent): bool=>$agent!=='overview'));if($withoutOverview)$effective=$withoutOverview;}
        $out=[];foreach($effective as $agent){$types=match($agent){'weather'=>['weather'],'flights'=>['flights'],'events'=>['events','weather'],'local'=>['places','weather'],'itinerary'=>['weather','events','places'],'budget'=>['flights'],default=>self::DATA_TYPES};foreach($types as $type)$out[$type]=true;}return array_values(array_keys($out));
    }

    private function agents(array $values): array
    {
        $allowed=['overview','weather','flights','events','local','itinerary','budget'];$out=[];foreach($values as $value){$agent=strtolower(trim((string)$value));if(in_array($agent,$allowed,true))$out[$agent]=true;}return array_values(array_keys($out));
    }

    private function assertTrip(int $userId,int $tripId): void
    {
        if($tripId<1)throw new InvalidArgumentException('Trip is required.');if(!(new DreamService($this->pdo))->get($userId,$tripId,false))throw new OutOfBoundsException('Trip not found.');
    }

    private function rawBatch(int $id): ?array
    {
        if(!$this->ready()||$id<1)return null;$stmt=$this->pdo->prepare('SELECT * FROM trip_agent_batches WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch()?:null;
    }

    private function publicBatch(array $row): array
    {
        return ['id'=>(int)$row['id'],'trip_id'=>(int)$row['dream_trip_id'],'scope'=>(string)$row['scope'],'status'=>(string)$row['status'],'agent_types'=>json_decode((string)$row['agent_types_json'],true)?:[],'required_types'=>json_decode((string)$row['required_types_json'],true)?:[],'refreshed_types'=>json_decode((string)($row['refreshed_types_json']??''),true)?:[],'snapshot_ids'=>json_decode((string)($row['snapshot_ids_json']??''),true)?:[],'attempt_count'=>(int)$row['attempt_count'],'error'=>(string)($row['error_message']??''),'queued_at'=>(string)$row['queued_at'],'ready_at'=>$row['ready_at']??null,'updated_at'=>(string)$row['updated_at']];
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before using coherent trip-agent intelligence batches.');
    }

    private function json(mixed $value): string
    {
        $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($json===false)throw new RuntimeException('Unable to encode trip-agent batch context.');return $json;
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}
