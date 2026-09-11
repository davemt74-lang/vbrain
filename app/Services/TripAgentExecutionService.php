<?php
declare(strict_types=1);

final class TripAgentExecutionService
{
    private const ACTIVE=['queued','agent_working','awaiting_approval','applied'];
    private const AGENTS=['overview','weather','flights','events','local','itinerary','budget'];
    private const AUTONOMY_TYPES=['planning_task','itinerary_item'];
    private const AUTONOMY_ITEMS=['idea','food','activity','experience'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_agent_action_executions')
            && db_table_exists('trip_agent_action_events')
            && db_table_exists('trip_agent_actions')
            && (new TripAgentJobService($this->pdo))->ready();
    }

    public function acceptAndStart(int $userId,int $tripId,int $actionId,?array $autonomyAudit=null): array
    {
        $this->requireReady();$action=$this->action($userId,$tripId,$actionId);
        if(in_array((string)$action['status'],['dismissed','completed','superseded'],true))throw new DomainException('This Next Move is no longer active.');
        $existing=$this->executionForAction($userId,$tripId,$actionId);
        if($existing&&in_array((string)$existing['status'],self::ACTIVE,true))return $existing;
        $isNew=$existing===null;$agent=$this->agent((string)($action['agent_type']??$action['target_tab']??'overview'));
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE trip_agent_actions SET status='accepted',accepted_at=COALESCE(accepted_at,NOW()),dismissed_at=NULL,completed_at=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$actionId,$userId,$tripId]);
            $stmt=$this->pdo->prepare("INSERT INTO trip_agent_action_executions (action_id,user_id,dream_trip_id,agent_type,status,queued_at) VALUES (?,?,?,?,'queued',NOW()) ON DUPLICATE KEY UPDATE agent_job_id=NULL,agent_type=VALUES(agent_type),status='queued',proposal_type=NULL,proposal_json=NULL,agent_output=NULL,error_message=NULL,queued_at=NOW(),started_at=NULL,proposed_at=NULL,approved_at=NULL,applied_at=NULL,completed_at=NULL,rejected_at=NULL,updated_at=NOW()");
            $stmt->execute([$actionId,$userId,$tripId,$agent]);$executionId=(int)($this->pdo->lastInsertId()?:0);if($executionId<1){$s=$this->pdo->prepare('SELECT id FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$s->execute([$actionId,$userId,$tripId]);$executionId=(int)$s->fetchColumn();}
            if(db_column_exists('trip_agent_action_executions','authorization_source'))$this->pdo->prepare('UPDATE trip_agent_action_executions SET authorization_source=NULL WHERE id=?')->execute([$executionId]);
            if($isNew)$this->event($executionId,$actionId,$userId,$tripId,'recommended',['title'=>$action['title'],'agent_type'=>$agent]);
            $this->event($executionId,$actionId,$userId,$tripId,'accepted',['title'=>$action['title'],'agent_type'=>$agent]);$this->event($executionId,$actionId,$userId,$tripId,'queued',['reason'=>'Awaiting specialist execution slot']);
            $this->autonomyDecision($autonomyAudit,$executionId,$actionId,$userId,$tripId,'started');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        try{$this->dispatchExecution($executionId);}catch(DomainException $e){}catch(Throwable $e){error_log('Trip Action immediate dispatch deferred for execution '.$executionId.': '.$this->clip($e->getMessage(),500));}
        return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    public function dispatchQueued(int $limit=10): array
    {
        $this->requireReady();$limit=max(1,min(25,$limit));$result=['checked'=>0,'dispatched'=>0,'deferred'=>0,'failed'=>0,'executions'=>[]];
        $rows=$this->pdo->query("SELECT id FROM trip_agent_action_executions WHERE status='queued' ORDER BY queued_at ASC,id ASC LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN)?:[];
        foreach($rows as $id){$result['checked']++;try{$out=$this->dispatchExecution((int)$id);if(($out['status']??'')==='agent_working'){$result['dispatched']++;$result['executions'][]=(int)$id;}else$result['deferred']++;}catch(DomainException $e){$result['deferred']++;}catch(Throwable $e){$result['failed']++;$this->markFailed((int)$id,$e->getMessage());}}
        return $result;
    }

    public function syncFinishedJobs(int $limit=25): array
    {
        $this->requireReady();$limit=max(1,min(100,$limit));$result=['checked'=>0,'proposed'=>0,'failed'=>0,'executions'=>[]];
        $sql="SELECT e.id,j.status job_status,j.result_json,j.error_message job_error FROM trip_agent_action_executions e JOIN trip_agent_jobs j ON j.id=e.agent_job_id WHERE e.status='agent_working' AND j.status IN ('completed','failed','cancelled') ORDER BY j.updated_at ASC,e.id ASC LIMIT $limit";
        $rows=$this->pdo->query($sql)->fetchAll()?:[];
        foreach($rows as $row){$result['checked']++;$id=(int)$row['id'];if((string)$row['job_status']!=='completed'){$this->markFailed($id,(string)($row['job_error']?:'The specialist agent did not complete.'));$result['failed']++;continue;}try{$created=$this->createProposal($id,(string)($row['result_json']??''));if($created){$result['proposed']++;$result['executions'][]=$id;}}catch(Throwable $e){$this->markFailed($id,$e->getMessage());$result['failed']++;}}
        return $result;
    }

    public function executionsForActions(int $userId,int $tripId,array $actionIds): array
    {
        if(!$this->ready()||!$actionIds)return [];$ids=array_values(array_unique(array_filter(array_map('intval',$actionIds),fn($v)=>$v>0)));if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([$userId,$tripId],$ids);$stmt=$this->pdo->prepare("SELECT * FROM trip_agent_action_executions WHERE user_id=? AND dream_trip_id=? AND action_id IN ($marks)");$stmt->execute($params);$out=[];foreach($stmt->fetchAll()?:[] as $row)$out[(int)$row['action_id']]=$this->publicExecution($row);return $out;
    }

    public function executionForAction(int $userId,int $tripId,int $actionId): ?array
    {
        if(!$this->ready())return null;$stmt=$this->pdo->prepare('SELECT * FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();return $row?$this->publicExecution($row):null;
    }

    public function history(int $userId,int $tripId,int $actionId,int $limit=30): array
    {
        if(!$this->ready())return [];$this->action($userId,$tripId,$actionId);$limit=max(1,min(100,$limit));$stmt=$this->pdo->prepare("SELECT event_type,detail_json,created_at FROM trip_agent_action_events WHERE action_id=? AND user_id=? AND dream_trip_id=? ORDER BY id DESC LIMIT $limit");$stmt->execute([$actionId,$userId,$tripId]);$rows=array_reverse($stmt->fetchAll()?:[]);foreach($rows as &$row){$row['detail']=json_decode((string)($row['detail_json']??''),true)?:[];unset($row['detail_json']);}unset($row);return $rows;
    }

    public function editProposal(int $userId,int $tripId,int $actionId,array $input): array
    {
        $this->requireReady();$row=$this->rawExecution($userId,$tripId,$actionId);if(!$row||$row['status']!=='awaiting_approval')throw new DomainException('This proposal is not waiting for approval.');$proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))throw new RuntimeException('Proposal data is unavailable.');
        if(array_key_exists('title',$input)){$proposal['title']=$this->clip((string)$input['title'],180);if($proposal['title']==='')throw new InvalidArgumentException('Proposal title is required.');}
        if(array_key_exists('notes',$input))$proposal['notes']=$this->clip((string)$input['notes'],1500);
        if(array_key_exists('price',$input)){$price=trim((string)$input['price']);$proposal['price']=$price===''?null:max(0,(float)$price);}
        if(array_key_exists('scheduled_date',$input))$proposal['scheduled_date']=$this->validDate((string)$input['scheduled_date']);
        if(array_key_exists('daypart',$input)){$daypart=(string)$input['daypart'];$proposal['daypart']=in_array($daypart,['morning','afternoon','evening','anytime'],true)?$daypart:null;}
        $json=$this->json($proposal);$stmt=$this->pdo->prepare("UPDATE trip_agent_action_executions SET proposal_json=?,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=? AND status='awaiting_approval'");$stmt->execute([$json,(int)$row['id'],$userId,$tripId]);if($stmt->rowCount()!==1)throw new DomainException('This proposal changed before your edit could be saved.');$this->event((int)$row['id'],$actionId,$userId,$tripId,'proposal_edited',['title'=>$proposal['title']??'']);return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    public function approveAndApply(int $userId,int $tripId,int $actionId,array $input=[]): array
    {
        $this->requireReady();if($input)$this->editProposal($userId,$tripId,$actionId,$input);$this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT * FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? FOR UPDATE");$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();if(!$row||$row['status']!=='awaiting_approval')throw new DomainException('This proposal is no longer waiting for approval.');
            $actionLock=$this->pdo->prepare('SELECT status FROM trip_agent_actions WHERE id=? AND user_id=? AND dream_trip_id=? FOR UPDATE');$actionLock->execute([$actionId,$userId,$tripId]);$actionStatus=(string)($actionLock->fetchColumn()?:'');if($actionStatus!=='accepted')throw new DomainException('This Next Move is no longer accepted, so the proposal was not applied.');
            $proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))throw new RuntimeException('Proposal data is unavailable.');$executionId=(int)$row['id'];
            $set="status='applied',approved_at=NOW(),applied_at=NOW(),error_message=NULL";if(db_column_exists('trip_agent_action_executions','authorization_source'))$set.=" ,authorization_source='user'";$this->pdo->prepare("UPDATE trip_agent_action_executions SET $set WHERE id=?")->execute([$executionId]);
            $this->event($executionId,$actionId,$userId,$tripId,'approved',['proposal_type'=>$row['proposal_type']]);$this->applyProposal($userId,$tripId,(string)$row['proposal_type'],$proposal);$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$executionId]);$this->pdo->prepare("UPDATE trip_agent_actions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$actionId,$userId,$tripId]);$this->event($executionId,$actionId,$userId,$tripId,'applied',['proposal_type'=>$row['proposal_type']]);$this->event($executionId,$actionId,$userId,$tripId,'completed',['source'=>'user_approval']);$this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    /**
     * Apply a standing-policy planning change without pretending the user clicked
     * transaction approval. Hard safety is repeated here so policy-engine bugs or
     * future callers cannot turn autonomy into provider/payment authority.
     */
    public function applyAutonomous(int $userId,int $tripId,int $actionId,string $policyRef='',?array $autonomyAudit=null): array
    {
        $this->requireReady();if(!db_column_exists('trip_agent_action_executions','authorization_source'))throw new RuntimeException('Run System Upgrade to enable autonomous planning authorization.');$this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT * FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? FOR UPDATE");$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();if(!$row||$row['status']!=='awaiting_approval')throw new DomainException('This proposal is no longer waiting for policy evaluation.');
            $actionLock=$this->pdo->prepare('SELECT status FROM trip_agent_actions WHERE id=? AND user_id=? AND dream_trip_id=? FOR UPDATE');$actionLock->execute([$actionId,$userId,$tripId]);if((string)($actionLock->fetchColumn()?:'')!=='accepted')throw new DomainException('This Next Move is no longer accepted.');
            $proposalType=(string)($row['proposal_type']??'');if(!in_array($proposalType,self::AUTONOMY_TYPES,true))throw new DomainException('Provider handoffs and budget decisions cannot be applied by standing autonomy policy.');
            $proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))throw new RuntimeException('Proposal data is unavailable.');
            if(!empty($proposal['requires_external_confirmation']))throw new DomainException('External/provider confirmation is required; autonomy cannot apply this proposal.');
            if(trim((string)($proposal['source_url']??''))!=='')throw new DomainException('External action URLs are not eligible for autonomous apply.');
            if(strcasecmp(trim((string)($proposal['source_provider']??'Vacation Brain')),'Vacation Brain')!==0)throw new DomainException('Only Vacation Brain-generated planning proposals are eligible for autonomous apply.');
            if(!str_starts_with((string)($proposal['source_external_id']??''),'trip-action:'))throw new DomainException('Proposal provenance is not the canonical Trip Action path.');
            if(!in_array((string)($proposal['item_type']??'idea'),self::AUTONOMY_ITEMS,true))throw new DomainException('Flight, lodging, merchandise, and unknown item types cannot be auto-applied.');
            $executionId=(int)$row['id'];$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='applied',authorization_source='autonomy_policy',approved_at=NULL,applied_at=NOW(),error_message=NULL WHERE id=?")->execute([$executionId]);
            $this->event($executionId,$actionId,$userId,$tripId,'policy_authorized',['proposal_type'=>$proposalType,'policy_ref'=>$this->clip($policyRef,180)]);$this->applyProposal($userId,$tripId,$proposalType,$proposal);$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$executionId]);$this->pdo->prepare("UPDATE trip_agent_actions SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$actionId,$userId,$tripId]);$this->event($executionId,$actionId,$userId,$tripId,'applied',['proposal_type'=>$proposalType,'authorization_source'=>'autonomy_policy']);$this->event($executionId,$actionId,$userId,$tripId,'completed',['source'=>'autonomy_policy']);
            $this->autonomyDecision($autonomyAudit,$executionId,$actionId,$userId,$tripId,'applied');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    public function reject(int $userId,int $tripId,int $actionId): array
    {
        $this->requireReady();$this->pdo->beginTransaction();try{$stmt=$this->pdo->prepare("SELECT * FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? FOR UPDATE");$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();if(!$row||!in_array((string)$row['status'],['awaiting_approval','failed'],true))throw new DomainException('This proposal cannot be rejected now.');$id=(int)$row['id'];$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='rejected',rejected_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);$this->pdo->prepare("UPDATE trip_agent_actions SET status='dismissed',dismissed_at=NOW(),updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$actionId,$userId,$tripId]);$this->event($id,$actionId,$userId,$tripId,'rejected',['source'=>'user']);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    public function release(int $userId,int $tripId,int $actionId): array
    {
        $this->requireReady();$row=$this->rawExecution($userId,$tripId,$actionId);if(!$row){(new TripAgentActionService($this->pdo))->updateStatus($userId,$tripId,$actionId,'open');return [];}$jobId=(int)($row['agent_job_id']??0);if($jobId>0){$job=(new TripAgentJobService($this->pdo))->jobForUser($userId,$jobId);if(($job['status']??'')==='running')throw new DomainException('The specialist is already working. Wait for the proposal before releasing this action.');if(($job['status']??'')==='queued')(new TripAgentJobService($this->pdo))->cancel($userId,$jobId);}
        $this->pdo->beginTransaction();try{$id=(int)$row['id'];$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='cancelled',updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$id,$userId,$tripId]);$this->pdo->prepare("UPDATE trip_agent_actions SET status='open',accepted_at=NULL,completed_at=NULL,dismissed_at=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND dream_trip_id=?")->execute([$actionId,$userId,$tripId]);$this->event($id,$actionId,$userId,$tripId,'cancelled',['source'=>'user_release']);$this->event($id,$actionId,$userId,$tripId,'reopened',[]);$this->pdo->commit();}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}return $this->executionForAction($userId,$tripId,$actionId)??[];
    }

    public function retry(int $userId,int $tripId,int $actionId): array
    {
        $row=$this->rawExecution($userId,$tripId,$actionId);if(!$row||!in_array((string)$row['status'],['failed','cancelled'],true))throw new DomainException('This execution is not retryable.');return $this->acceptAndStart($userId,$tripId,$actionId);
    }

    private function dispatchExecution(int $executionId): array
    {
        $stmt=$this->pdo->prepare("SELECT e.*,a.title,a.body,a.action_kind,a.target_tab,a.suggestion_key,a.status action_status FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id WHERE e.id=? LIMIT 1");$stmt->execute([$executionId]);$row=$stmt->fetch();if(!$row||$row['status']!=='queued')return $row?$this->publicExecution($row):[];if((string)$row['action_status']!=='accepted'){return $this->cancelStale($row,'Next Move is no longer accepted.');}$userId=(int)$row['user_id'];$tripId=(int)$row['dream_trip_id'];$jobService=new TripAgentJobService($this->pdo);if($jobService->hasActiveTripJobs($userId,$tripId))return $this->publicExecution($row);
        $agent=$this->agent((string)$row['agent_type']);$request=$this->executionRequest($row);$job=$jobService->enqueue($userId,$tripId,$agent,$request);$jobId=(int)($job['id']??0);if($jobId<1)throw new RuntimeException('Unable to queue the specialist execution job.');$update=$this->pdo->prepare("UPDATE trip_agent_action_executions SET agent_job_id=?,status='agent_working',started_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=? AND status='queued'");$update->execute([$jobId,$executionId]);if($update->rowCount()!==1){$current=$this->rawExecution($userId,$tripId,(int)$row['action_id']);return $current?$this->publicExecution($current):[];}$this->event($executionId,(int)$row['action_id'],$userId,$tripId,'agent_working',['agent_type'=>$agent,'job_id'=>$jobId]);return $this->executionForAction($userId,$tripId,(int)$row['action_id'])??[];
    }

    private function createProposal(int $executionId,string $resultJson): bool
    {
        $stmt=$this->pdo->prepare("SELECT e.*,a.title,a.body,a.action_kind,a.target_tab,a.suggestion_key,a.metadata_json action_metadata,a.status action_status FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id WHERE e.id=? LIMIT 1");$stmt->execute([$executionId]);$row=$stmt->fetch();if(!$row||$row['status']!=='agent_working')return false;if((string)$row['action_status']!=='accepted'){$this->cancelStale($row,'Next Move is no longer accepted.');return false;}$decoded=json_decode($resultJson,true);$agentOutput=$this->clip((string)($decoded['message']??''),6000);$proposal=$this->proposal($row,$agentOutput);$json=$this->json($proposal['data']);$update=$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='awaiting_approval',proposal_type=?,proposal_json=?,agent_output=?,proposed_at=NOW(),error_message=NULL,updated_at=NOW() WHERE id=? AND status='agent_working'");$update->execute([$proposal['type'],$json,$agentOutput?:null,$executionId]);if($update->rowCount()!==1)return false;$this->event($executionId,(int)$row['action_id'],(int)$row['user_id'],(int)$row['dream_trip_id'],'proposal_ready',['proposal_type'=>$proposal['type'],'title'=>$proposal['data']['title']]);try{(new NotificationService($this->pdo))->create((int)$row['user_id'],'trip_action_approval','Trip change proposal ready',(string)$proposal['data']['title'],app_url('dream-trip.php?id='.(int)$row['dream_trip_id'].'&tab=overview#next-moves'),null,null,'trip-action-execution:'.$executionId.':proposal');}catch(Throwable $e){}return true;
    }

    private function proposal(array $row,string $agentOutput): array
    {
        $agent=$this->agent((string)$row['agent_type']);$key=(string)$row['suggestion_key'];$kind=(string)$row['action_kind'];$title=$this->clip((string)$row['title'],180);$body=$this->clip((string)$row['body'],900);$notes=$this->clip(($body!==''?$body.' ':'').($agentOutput!==''?'Specialist analysis: '.$agentOutput:''),1500);$type='planning_task';$itemType='idea';$external=false;
        if($agent==='flights'||str_contains($key,'fare')||str_contains($key,'flight')){$type='booking_handoff';$itemType='flight';$external=true;}elseif($key==='lodging-partner'||str_contains($key,'lodging')){$type='booking_handoff';$itemType='hotel';$external=true;}elseif($agent==='events'){$type='itinerary_item';$itemType='activity';}elseif($agent==='local'){$type='itinerary_item';$itemType=(preg_match('/restaurant|dining|food|cafe|bar/i',$title.' '.$body)?'food':'activity');}elseif($agent==='itinerary'){$type='itinerary_item';$itemType='experience';}elseif($agent==='weather'){$type='itinerary_item';$itemType='idea';}elseif($agent==='budget'||$kind==='budget'){$type='budget_review';$itemType='idea';}
        $prefix=$external?'Booking handoff: ':($type==='budget_review'?'Budget decision: ':($type==='planning_task'?'Planning task: ':''));$data=['title'=>$this->clip($prefix.$title,180),'notes'=>$notes,'item_type'=>$itemType,'price'=>null,'scheduled_date'=>null,'daypart'=>null,'source_provider'=>'Vacation Brain','source_external_id'=>'trip-action:'.(int)$row['action_id'],'source_url'=>null,'requires_external_confirmation'=>$external,'approval_note'=>$external?'Vacation Brain will save this as a booking handoff. It will not claim a reservation, ticket, fare, or payment was completed.':'Approval applies this proposed change to the trip.'];return ['type'=>$type,'data'=>$data];
    }

    private function applyProposal(int $userId,int $tripId,string $proposalType,array $proposal): void
    {
        $title=$this->clip((string)($proposal['title']??''),180);if($title==='')throw new InvalidArgumentException('Proposal title is required.');$notes=$this->clip((string)($proposal['notes']??''),1500);if($proposalType==='booking_handoff')$notes='Booking handoff — confirm live availability, final price, terms, and payment with the provider before booking. '.$notes;$input=['item_type'=>$this->itemType((string)($proposal['item_type']??'idea')),'title'=>$title,'notes'=>$notes,'price'=>$proposal['price']??null,'scheduled_date'=>$proposal['scheduled_date']??null,'daypart'=>$proposal['daypart']??null,'source_provider'=>$proposal['source_provider']??'Vacation Brain','source_external_id'=>$proposal['source_external_id']??null,'source_url'=>$proposal['source_url']??null];(new DreamService($this->pdo))->addItem($userId,$tripId,$input);
    }

    private function executionRequest(array $row): string
    {
        $label=ucfirst((string)$row['agent_type']).' Agent';return "Execute the accepted Vacation Brain Next Move as a proposal only. Do not modify the trip and do not claim anything was booked or purchased. Next Move: ".(string)$row['title'].". Context: ".(string)$row['body'].". Produce a concrete, concise proposed change for user approval or saved standing-policy evaluation. If this involves flights, lodging, tickets, reservations, provider checkout, cancellations, purchases, refunds, or payments, treat it as a booking/partner handoff and clearly require explicit user/provider confirmation. Focus as the $label.";
    }

    private function cancelStale(array $row,string $reason): array
    {
        $id=(int)$row['id'];$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='cancelled',error_message=?,updated_at=NOW() WHERE id=? AND status IN ('queued','agent_working')")->execute([$this->clip($reason,1000),$id]);$this->event($id,(int)$row['action_id'],(int)$row['user_id'],(int)$row['dream_trip_id'],'cancelled',['reason'=>$reason]);$fresh=$this->rawExecution((int)$row['user_id'],(int)$row['dream_trip_id'],(int)$row['action_id']);return $fresh?$this->publicExecution($fresh):[];
    }

    private function markFailed(int $executionId,string $message): void
    {
        if(!$this->ready())return;$message=$this->clip($message,1000);$stmt=$this->pdo->prepare('SELECT action_id,user_id,dream_trip_id,status FROM trip_agent_action_executions WHERE id=? LIMIT 1');$stmt->execute([$executionId]);$row=$stmt->fetch();if(!$row||in_array((string)$row['status'],['completed','rejected','cancelled'],true))return;$this->pdo->prepare("UPDATE trip_agent_action_executions SET status='failed',error_message=?,updated_at=NOW() WHERE id=?")->execute([$message,$executionId]);$this->event($executionId,(int)$row['action_id'],(int)$row['user_id'],(int)$row['dream_trip_id'],'failed',['error'=>$message]);
    }

    private function rawExecution(int $userId,int $tripId,int $actionId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM trip_agent_action_executions WHERE action_id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$actionId,$userId,$tripId]);return $stmt->fetch()?:null;
    }

    private function action(int $userId,int $tripId,int $actionId): array
    {
        if($actionId<1)throw new InvalidArgumentException('Trip action is required.');$stmt=$this->pdo->prepare('SELECT * FROM trip_agent_actions WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$actionId,$userId,$tripId]);$row=$stmt->fetch();if(!$row)throw new OutOfBoundsException('Trip action not found.');return $row;
    }

    private function publicExecution(array $row): array
    {
        $proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))$proposal=[];return ['id'=>(int)$row['id'],'action_id'=>(int)$row['action_id'],'trip_id'=>(int)$row['dream_trip_id'],'job_id'=>(int)($row['agent_job_id']??0)?:null,'agent_type'=>(string)$row['agent_type'],'status'=>(string)$row['status'],'proposal_type'=>(string)($row['proposal_type']??''),'proposal'=>$proposal,'agent_output'=>(string)($row['agent_output']??''),'error'=>(string)($row['error_message']??''),'queued_at'=>$row['queued_at']??null,'started_at'=>$row['started_at']??null,'proposed_at'=>$row['proposed_at']??null,'approved_at'=>$row['approved_at']??null,'authorization_source'=>$row['authorization_source']??null,'applied_at'=>$row['applied_at']??null,'completed_at'=>$row['completed_at']??null,'updated_at'=>(string)$row['updated_at']];
    }

    private function event(int $executionId,int $actionId,int $userId,int $tripId,string $type,array $detail): void
    {
        if($executionId<1)return;$this->pdo->prepare('INSERT INTO trip_agent_action_events (execution_id,action_id,user_id,dream_trip_id,event_type,detail_json) VALUES (?,?,?,?,?,?)')->execute([$executionId,$actionId,$userId,$tripId,$type,$this->json($detail)]);
    }

    private function autonomyDecision(?array $audit,int $executionId,int $actionId,int $userId,int $tripId,string $type): void
    {
        if(!$audit||!db_table_exists('trip_autonomy_decisions'))return;$key=strtolower(trim((string)($audit['decision_key']??'')));if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new RuntimeException('Autonomy audit key is invalid.');$proposalType=trim((string)($audit['proposal_type']??''));if($proposalType==='')$proposalType=null;$amount=array_key_exists('estimated_amount',$audit)&&is_numeric($audit['estimated_amount'])?max(0,(float)$audit['estimated_amount']):null;$reason=$this->clip((string)($audit['reason']??'Standing autonomy policy decision.'),700);$policy=is_array($audit['policy']??null)?$audit['policy']:[];$detail=is_array($audit['detail']??null)?$audit['detail']:[];
        $sql="INSERT INTO trip_autonomy_decisions (user_id,dream_trip_id,action_id,execution_id,decision_key,decision_type,proposal_type,reason,estimated_amount,policy_json,detail_json) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE decision_key=VALUES(decision_key)";$this->pdo->prepare($sql)->execute([$userId,$tripId,$actionId,$executionId,$key,$type,$proposalType,$reason,$amount,$this->json($policy),$this->json($detail)]);
    }

    private function validDate(string $value): ?string
    {
        $value=trim($value);if($value==='')return null;$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$dt||$dt->format('Y-m-d')!==$value)throw new InvalidArgumentException('Use a valid proposal date.');return $value;
    }

    private function itemType(string $value): string{return in_array($value,['idea','hotel','food','activity','flight','experience','merch'],true)?$value:'idea';}
    private function agent(string $value): string{$value=strtolower(trim($value));return in_array($value,self::AGENTS,true)?$value:'overview';}
    private function json(array $value): string{$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);return $json===false?'{}':$json;}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade before using Trip Action execution and approval.');}
    private function clip(string $value,int $max): string{$value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
}
