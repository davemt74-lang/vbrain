<?php
declare(strict_types=1);

final class TripAgentActionService
{
    private const TABS=['overview','weather','flights','events','local','itinerary','budget'];
    private const USER_STATUSES=['open','accepted','dismissed','completed'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_agent_actions')
            && db_table_exists('trip_agent_batches')
            && db_column_exists('trip_agent_batches','actions_synced_at');
    }

    public function syncCompletedOverviewBatches(int $limit=20): array
    {
        $this->requireReady();$limit=max(1,min(50,$limit));$result=['checked'=>0,'synced'=>0,'failed'=>0,'batches'=>[]];
        $sql="SELECT b.id,b.user_id,b.dream_trip_id
              FROM trip_agent_batches b
              WHERE b.actions_synced_at IS NULL
                AND EXISTS (SELECT 1 FROM trip_agent_jobs j WHERE j.batch_id=b.id AND j.agent_type='overview' AND j.status='completed')
              ORDER BY COALESCE(b.completed_at,b.updated_at) ASC,b.id ASC LIMIT $limit";
        $rows=$this->pdo->query($sql)->fetchAll()?:[];$batchService=new TripAgentBatchService($this->pdo);
        foreach($rows as $row){$result['checked']++;$batchId=(int)$row['id'];$userId=(int)$row['user_id'];$tripId=(int)$row['dream_trip_id'];
            try{
                $dashboard=$batchService->dashboardForJob(['batch_id'=>$batchId,'user_id'=>$userId,'dream_trip_id'=>$tripId,'agent_type'=>'overview']);
                if(!is_array($dashboard))throw new RuntimeException('Completed Overview batch context is unavailable.');
                $this->syncFromDashboard($userId,$tripId,$dashboard,$batchId);
                $stmt=$this->pdo->prepare('UPDATE trip_agent_batches SET actions_synced_at=NOW() WHERE id=? AND actions_synced_at IS NULL');$stmt->execute([$batchId]);$result['synced']++;$result['batches'][]=$batchId;
            }catch(Throwable $e){$result['failed']++;}
        }
        return $result;
    }

    public function syncFromDashboard(int $userId,int $tripId,array $dashboard,?int $batchId=null): array
    {
        $this->requireReady();
        $trip=$dashboard['trip']??[];
        if((int)($trip['id']??0)!==$tripId||(int)($trip['user_id']??0)!==$userId){
            $owned=(new DreamService($this->pdo))->get($userId,$tripId,false);
            if(!$owned)throw new OutOfBoundsException('Trip not found.');
        }
        $overview=(new TripSupervisorService($this->pdo))->overview($userId,$tripId,$dashboard);
        $suggestions=is_array($overview['suggestions']??null)?array_slice($overview['suggestions'],0,8):[];
        $batch=is_array($dashboard['_agent_batch']??null)?$dashboard['_agent_batch']:[];
        $snapshotIds=is_array($batch['snapshot_ids']??null)?$batch['snapshot_ids']:[];
        $preparedAt=(string)($batch['prepared_at']??'');
        $active=[];$ownTx=!$this->pdo->inTransaction();
        if($ownTx)$this->pdo->beginTransaction();
        try{
            foreach($suggestions as $suggestion){
                if(!is_array($suggestion))continue;
                $key=$this->key((string)($suggestion['key']??''));$title=$this->clip((string)($suggestion['title']??''),180);$body=$this->clip((string)($suggestion['body']??''),700);
                if($key===''||$title==='')continue;
                $kind=$this->slug((string)($suggestion['kind']??'planning'),32)?:'planning';$priority=max(0,min(100,(int)($suggestion['priority']??50)));$target=$this->tab((string)($suggestion['targetTab']??'overview'));$agent=$target;
                $sourceKey=$key.':'.substr(hash('sha256',$title.'|'.$body.'|'.$target),0,32);$active[]=$sourceKey;
                $metadata=['source'=>'trip_supervisor','suggestion_key'=>$key,'batch_id'=>$batchId,'snapshot_ids'=>$snapshotIds,'prepared_at'=>$preparedAt?:null];
                $json=json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);if($json===false)$json='{}';
                $sql="INSERT INTO trip_agent_actions (user_id,dream_trip_id,batch_id,suggestion_key,source_key,agent_type,action_kind,title,body,priority,target_tab,status,metadata_json)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,'open',?)
                      ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id),agent_type=VALUES(agent_type),action_kind=VALUES(action_kind),title=VALUES(title),body=VALUES(body),priority=VALUES(priority),target_tab=VALUES(target_tab),metadata_json=VALUES(metadata_json),status=IF(status='superseded','open',status),updated_at=NOW()";
                $this->pdo->prepare($sql)->execute([$userId,$tripId,$batchId,$key,$sourceKey,$agent,$kind,$title,$body,$priority,$target,$json]);
            }
            if($active){$marks=implode(',',array_fill(0,count($active),'?'));$params=array_merge([$userId,$tripId],$active);$stmt=$this->pdo->prepare("UPDATE trip_agent_actions SET status='superseded',updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND status='open' AND source_key NOT IN ($marks)");$stmt->execute($params);}
            else{$this->pdo->prepare("UPDATE trip_agent_actions SET status='superseded',updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND status='open'")->execute([$userId,$tripId]);}
            if($ownTx)$this->pdo->commit();
        }catch(Throwable $e){if($ownTx&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->queueForTrip($userId,$tripId,16);
    }

    public function queueForTrip(int $userId,int $tripId,int $limit=16): array
    {
        if(!$this->ready())return [];$this->assertTrip($userId,$tripId);$limit=max(1,min(50,$limit));
        $stmt=$this->pdo->prepare("SELECT * FROM trip_agent_actions WHERE user_id=? AND dream_trip_id=? AND status IN ('open','accepted') ORDER BY FIELD(status,'accepted','open'),priority DESC,updated_at DESC,id DESC LIMIT $limit");$stmt->execute([$userId,$tripId]);$rows=$stmt->fetchAll()?:[];
        foreach($rows as &$row){$row=$this->publicAction($row);}unset($row);return $rows;
    }

    public function updateStatus(int $userId,int $tripId,int $actionId,string $status): array
    {
        $this->requireReady();$status=strtolower(trim($status));if(!in_array($status,self::USER_STATUSES,true))throw new InvalidArgumentException('Unknown action status.');$this->assertTrip($userId,$tripId);
        $stmt=$this->pdo->prepare('SELECT * FROM trip_agent_actions WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Trip action not found.');
        if($status==='open'){$sql="UPDATE trip_agent_actions SET status='open',accepted_at=NULL,dismissed_at=NULL,completed_at=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?";$params=[$actionId,$userId,$tripId];}
        elseif($status==='accepted'){$sql="UPDATE trip_agent_actions SET status='accepted',accepted_at=COALESCE(accepted_at,NOW()),dismissed_at=NULL,completed_at=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?";$params=[$actionId,$userId,$tripId];}
        elseif($status==='dismissed'){$sql="UPDATE trip_agent_actions SET status='dismissed',dismissed_at=NOW(),completed_at=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?";$params=[$actionId,$userId,$tripId];}
        else{$sql="UPDATE trip_agent_actions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?";$params=[$actionId,$userId,$tripId];}
        $this->pdo->prepare($sql)->execute($params);$stmt=$this->pdo->prepare('SELECT * FROM trip_agent_actions WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$actionId,$userId,$tripId]);$updated=$stmt->fetch();return $updated?$this->publicAction($updated):[];
    }

    public function counts(int $userId,int $tripId): array
    {
        $out=['open'=>0,'accepted'=>0,'dismissed'=>0,'completed'=>0];if(!$this->ready())return $out;$this->assertTrip($userId,$tripId);$stmt=$this->pdo->prepare("SELECT status,COUNT(*) total FROM trip_agent_actions WHERE user_id=? AND dream_trip_id=? AND status IN ('open','accepted','dismissed','completed') GROUP BY status");$stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll()?:[] as $row)$out[(string)$row['status']]=(int)$row['total'];return $out;
    }

    private function publicAction(array $row): array
    {
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];$tab=$this->tab((string)($row['target_tab']??'overview'));
        return ['id'=>(int)$row['id'],'trip_id'=>(int)$row['dream_trip_id'],'batch_id'=>(int)($row['batch_id']??0)?:null,'suggestion_key'=>(string)$row['suggestion_key'],'source_key'=>(string)$row['source_key'],'agent_type'=>(string)$row['agent_type'],'kind'=>(string)$row['action_kind'],'title'=>(string)$row['title'],'body'=>(string)$row['body'],'priority'=>(int)$row['priority'],'target_tab'=>$tab,'status'=>(string)$row['status'],'metadata'=>$meta,'url'=>app_url('dream-trip.php?id='.(int)$row['dream_trip_id'].'&tab='.rawurlencode($tab).'#agent-results'),'updated_at'=>(string)$row['updated_at']];
    }

    private function assertTrip(int $userId,int $tripId): void
    {
        if($tripId<1||!(new DreamService($this->pdo))->get($userId,$tripId,false))throw new OutOfBoundsException('Trip not found.');
    }

    private function tab(string $value): string
    {
        $value=strtolower(trim($value));return in_array($value,self::TABS,true)?$value:'overview';
    }

    private function key(string $value): string{return $this->slug($value,80);}
    private function slug(string $value,int $max): string
    {
        $value=strtolower(trim($value));$value=preg_replace('/[^a-z0-9_-]+/','-',$value)??'';$value=trim($value,'-');return substr($value,0,$max);
    }

    private function requireReady(): void
    {
        if(!$this->ready())throw new RuntimeException('Run System Upgrade before using the Trip Agent action queue.');
    }

    private function clip(string $value,int $max): string
    {
        $value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);
    }
}