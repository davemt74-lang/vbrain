<?php
declare(strict_types=1);

/**
 * Opt-in standing policy for Vacation Brain travel operations.
 *
 * The policy may automatically start specialist research and may apply only the
 * canonical non-transactional planning_task / itinerary_item proposals created by
 * TripAgentExecutionService. It never authorizes provider checkout, reservations,
 * purchases, ticketing, payments, refunds, cancellations, or destructive mutation.
 */
final class AutonomousTravelOperationsService
{
    private const MODES=['observe','research','planning'];
    private const SAFE_PROPOSAL_TYPES=['planning_task','itinerary_item'];
    private const SAFE_ITEM_TYPES=['idea','food','activity','experience'];
    private const SAFE_SOURCES=['proactive_travel','trip_supervisor'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_autonomy_controls')
            && db_table_exists('trip_autonomy_decisions')
            && db_column_exists('trip_agent_action_executions','authorization_source');
    }

    public function settings(int $userId,int $tripId): array
    {
        $this->assertTrip($userId,$tripId);$defaults=$this->defaults();if(!$this->ready())return $defaults;
        $stmt=$this->pdo->prepare('SELECT * FROM trip_autonomy_controls WHERE user_id=? AND dream_trip_id=? LIMIT 1');$stmt->execute([$userId,$tripId]);$row=$stmt->fetch();if(!$row)return $defaults;
        return $this->policyFromRow($row)+['exists'=>true,'updated_at'=>(string)$row['updated_at']];
    }

    public function saveSettings(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$this->assertTrip($userId,$tripId);$mode=$this->mode((string)($input['autonomy_mode']??'observe'));
        $types=[];if(!empty($input['allow_planning_task']))$types[]='planning_task';if(!empty($input['allow_itinerary_item']))$types[]='itinerary_item';
        $minimum=max(0,min(100,(int)($input['minimum_priority']??90)));$starts=max(0,min(24,(int)($input['max_auto_starts_per_day']??3)));$applies=max(0,min(24,(int)($input['max_auto_applies_per_day']??2)));$perAction=$this->money($input['per_action_estimated_limit']??0);$daily=$this->money($input['daily_estimated_limit']??0);$enabled=!empty($input['enabled'])?1:0;$pause=!empty($input['pause_on_verification_pending'])?1:0;$notify=!empty($input['notifications_enabled'])?1:0;
        return $this->withTripLock($tripId,function() use($userId,$tripId,$enabled,$mode,$minimum,$starts,$applies,$perAction,$daily,$types,$pause,$notify){
            $sql="INSERT INTO trip_autonomy_controls (user_id,dream_trip_id,enabled,autonomy_mode,minimum_priority,max_auto_starts_per_day,max_auto_applies_per_day,per_action_estimated_limit,daily_estimated_limit,allowed_proposal_types_json,pause_on_verification_pending,notifications_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),autonomy_mode=VALUES(autonomy_mode),minimum_priority=VALUES(minimum_priority),max_auto_starts_per_day=VALUES(max_auto_starts_per_day),max_auto_applies_per_day=VALUES(max_auto_applies_per_day),per_action_estimated_limit=VALUES(per_action_estimated_limit),daily_estimated_limit=VALUES(daily_estimated_limit),allowed_proposal_types_json=VALUES(allowed_proposal_types_json),pause_on_verification_pending=VALUES(pause_on_verification_pending),notifications_enabled=VALUES(notifications_enabled),updated_at=NOW()";
            $this->pdo->prepare($sql)->execute([$userId,$tripId,$enabled,$mode,$minimum,$starts,$applies,$perAction,$daily,$this->json($types),$pause,$notify]);return $this->settings($userId,$tripId);
        });
    }

    public function status(int $userId,int $tripId): array
    {
        $policy=$this->settings($userId,$tripId);$usage=$this->usage($userId,$tripId);$recent=[];$pending=0;
        if($this->ready()){
            $stmt=$this->pdo->prepare('SELECT decision_type,proposal_type,reason,estimated_amount,detail_json,created_at FROM trip_autonomy_decisions WHERE user_id=? AND dream_trip_id=? ORDER BY id DESC LIMIT 20');$stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll()?:[] as $row){$row['detail']=json_decode((string)($row['detail_json']??''),true)?:[];unset($row['detail_json']);$recent[]=$row;}
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM trip_agent_action_executions WHERE user_id=? AND dream_trip_id=? AND status='awaiting_approval'");$q->execute([$userId,$tripId]);$pending=(int)$q->fetchColumn();
        }
        return ['policy'=>$policy,'usage'=>$usage,'recent'=>$recent,'pending_approvals'=>$pending,'verification_pending'=>$this->verificationPending($userId,$tripId)];
    }

    /** Automatically accept/start eligible Next Moves; no trip mutation occurs here. */
    public function startEligible(int $limit=20): array
    {
        if(!$this->ready())return ['ready'=>false,'checked'=>0,'started'=>0,'blocked'=>0,'failed'=>0];$limit=max(1,min(50,$limit));
        $sql="SELECT a.id,a.user_id,a.dream_trip_id FROM trip_agent_actions a JOIN trip_autonomy_controls c ON c.user_id=a.user_id AND c.dream_trip_id=a.dream_trip_id JOIN dream_trips d ON d.id=a.dream_trip_id AND d.user_id=a.user_id WHERE c.enabled=1 AND c.autonomy_mode IN ('research','planning') AND a.status='open' AND a.priority>=c.minimum_priority AND d.status NOT IN ('abandoned','completed') ORDER BY a.priority DESC,a.updated_at ASC,a.id ASC LIMIT $limit";
        $rows=$this->pdo->query($sql)->fetchAll()?:[];$result=['ready'=>true,'checked'=>0,'started'=>0,'blocked'=>0,'failed'=>0];$execution=new TripAgentExecutionService($this->pdo);
        foreach($rows as $seed){$result['checked']++;$userId=(int)$seed['user_id'];$tripId=(int)$seed['dream_trip_id'];$actionId=(int)$seed['id'];
            try{$outcome=$this->withTripLock($tripId,function() use($userId,$tripId,$actionId,$execution){$row=$this->startRow($userId,$tripId,$actionId);if(!$row)return 'blocked';$policy=$this->policyFromRow($row);[$ok,$reason]=$this->canStart($row,$policy);if(!$ok){$this->recordDecision($userId,$tripId,$actionId,null,'blocked',null,$reason,null,$policy,['stage'=>'start']);return 'blocked';}$existing=$execution->executionForAction($userId,$tripId,$actionId);if($existing&&in_array((string)($existing['status']??''),['failed','cancelled','rejected'],true)){$reason='A prior execution stopped; manual retry is required.';$this->recordDecision($userId,$tripId,$actionId,(int)($existing['id']??0)?:null,'blocked',null,$reason,null,$policy,['stage'=>'start']);return 'blocked';}
                $audit=$this->auditPayload($userId,$tripId,$actionId,null,'started',null,'Standing autonomy policy started specialist research.',null,$policy,['priority'=>(int)$row['priority'],'title'=>(string)$row['title']]);$execution->acceptAndStart($userId,$tripId,$actionId,$audit);return 'started';});$result[$outcome]++;}
            catch(DomainException $e){$result['blocked']++;}
            catch(Throwable $e){error_log('Autonomous travel start failed: '.$this->clip($e->getMessage(),500));$result['failed']++;}
        }
        return $result;
    }

    /** Apply only canonical low-risk planning proposals that pass the saved standing policy. */
    public function applyEligible(int $limit=20): array
    {
        if(!$this->ready())return ['ready'=>false,'checked'=>0,'applied'=>0,'blocked'=>0,'failed'=>0];$limit=max(1,min(50,$limit));
        $sql="SELECT e.id,e.user_id,e.dream_trip_id,e.action_id FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id AND a.user_id=e.user_id AND a.dream_trip_id=e.dream_trip_id JOIN trip_autonomy_controls c ON c.user_id=e.user_id AND c.dream_trip_id=e.dream_trip_id JOIN dream_trips d ON d.id=e.dream_trip_id AND d.user_id=e.user_id WHERE c.enabled=1 AND c.autonomy_mode='planning' AND e.status='awaiting_approval' AND a.status='accepted' AND d.status NOT IN ('abandoned','completed') ORDER BY a.priority DESC,e.proposed_at ASC,e.id ASC LIMIT $limit";
        $rows=$this->pdo->query($sql)->fetchAll()?:[];$result=['ready'=>true,'checked'=>0,'applied'=>0,'blocked'=>0,'failed'=>0];$execution=new TripAgentExecutionService($this->pdo);
        foreach($rows as $seed){$result['checked']++;$userId=(int)$seed['user_id'];$tripId=(int)$seed['dream_trip_id'];$actionId=(int)$seed['action_id'];$executionId=(int)$seed['id'];
            try{$outcome=$this->withTripLock($tripId,function() use($userId,$tripId,$actionId,$executionId,$execution){$row=$this->applyRow($userId,$tripId,$executionId);if(!$row)return 'blocked';$policy=$this->policyFromRow($row);[$ok,$reason,$amount,$proposal]=$this->canApply($row,$policy);if(!$ok){$this->recordDecision($userId,$tripId,$actionId,$executionId,'blocked',(string)($row['proposal_type']??''),$reason,$amount,$policy,['stage'=>'apply','proposal_title'=>(string)($proposal['title']??'')]);return 'blocked';}
                $reason='Standing autonomy policy applied a non-transactional trip planning change.';$audit=$this->auditPayload($userId,$tripId,$actionId,$executionId,'applied',(string)$row['proposal_type'],$reason,$amount,$policy,['proposal_title'=>(string)($proposal['title']??''),'item_type'=>(string)($proposal['item_type']??'')]);$execution->applyAutonomous($userId,$tripId,$actionId,'trip-autonomy:'.$tripId,$audit);$this->notifyApplied($userId,$tripId,$executionId,$proposal,$policy);return 'applied';});$result[$outcome]++;}
            catch(DomainException $e){$result['blocked']++;}
            catch(Throwable $e){error_log('Autonomous travel apply failed: '.$this->clip($e->getMessage(),500));$result['failed']++;}
        }
        return $result;
    }

    public function agentContext(int $userId,int $tripId): string
    {
        if(!$this->ready())return '';try{$status=$this->status($userId,$tripId);}catch(Throwable){return '';}$p=$status['policy'];if(empty($p['exists'])&&!$status['recent'])return '';$mode=empty($p['enabled'])?'off':(string)$p['mode'];$usage=$status['usage'];$recent=[];foreach(array_slice($status['recent'],0,5) as $row)$recent[]=strtoupper((string)$row['decision_type']).' '.(string)$row['reason'];
        return 'AUTONOMOUS TRAVEL STATE (saved standing policy + audit ledger only): mode '.$mode.'; automatic research starts today '.(int)$usage['started'].'/'.(int)$p['max_auto_starts_per_day'].'; automatic planning applies today '.(int)$usage['applied'].'/'.(int)$p['max_auto_applies_per_day'].'. '.($recent?'Recent: '.implode('; ',$recent).'. ':'').'The user’s saved policy is standing approval only for eligible non-transactional planning additions. It never approves provider checkout, bookings, reservations, cancellations, purchases, ticketing, payments, refunds, destructive mutations, or a provider action in verification-pending state.';
    }

    public function fallback(int $userId,int $tripId): array{return $this->status($userId,$tripId);}

    private function startRow(int $userId,int $tripId,int $actionId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT a.*,c.enabled,c.autonomy_mode,c.minimum_priority,c.max_auto_starts_per_day,c.max_auto_applies_per_day,c.per_action_estimated_limit,c.daily_estimated_limit,c.allowed_proposal_types_json,c.pause_on_verification_pending,c.notifications_enabled FROM trip_agent_actions a JOIN trip_autonomy_controls c ON c.user_id=a.user_id AND c.dream_trip_id=a.dream_trip_id JOIN dream_trips d ON d.id=a.dream_trip_id AND d.user_id=a.user_id WHERE a.id=? AND a.user_id=? AND a.dream_trip_id=? AND a.status='open' AND c.enabled=1 AND c.autonomy_mode IN ('research','planning') AND d.status NOT IN ('abandoned','completed') LIMIT 1");$stmt->execute([$actionId,$userId,$tripId]);return $stmt->fetch()?:null;
    }

    private function applyRow(int $userId,int $tripId,int $executionId): ?array
    {
        $stmt=$this->pdo->prepare("SELECT e.*,a.priority,a.title action_title,a.metadata_json action_metadata,a.status action_status,c.enabled,c.autonomy_mode,c.minimum_priority,c.max_auto_starts_per_day,c.max_auto_applies_per_day,c.per_action_estimated_limit,c.daily_estimated_limit,c.allowed_proposal_types_json,c.pause_on_verification_pending,c.notifications_enabled FROM trip_agent_action_executions e JOIN trip_agent_actions a ON a.id=e.action_id AND a.user_id=e.user_id AND a.dream_trip_id=e.dream_trip_id JOIN trip_autonomy_controls c ON c.user_id=e.user_id AND c.dream_trip_id=e.dream_trip_id JOIN dream_trips d ON d.id=e.dream_trip_id AND d.user_id=e.user_id WHERE e.id=? AND e.user_id=? AND e.dream_trip_id=? AND e.status='awaiting_approval' AND a.status='accepted' AND c.enabled=1 AND c.autonomy_mode='planning' AND d.status NOT IN ('abandoned','completed') LIMIT 1");$stmt->execute([$executionId,$userId,$tripId]);return $stmt->fetch()?:null;
    }

    private function canStart(array $row,array $policy): array
    {
        if(empty($policy['enabled'])||!in_array($policy['mode'],['research','planning'],true))return [false,'Automatic research is not enabled for this trip.'];if((int)$row['priority']<(int)$policy['minimum_priority'])return [false,'Next Move priority is below the saved autonomy threshold.'];
        $meta=json_decode((string)($row['metadata_json']??''),true);if(!is_array($meta))$meta=[];$source=(string)($meta['source']??'');if(!in_array($source,self::SAFE_SOURCES,true))return [false,'Only Vacation Brain proactive/supervisor Next Moves can be started automatically.'];
        if($this->hasDecision((int)$row['user_id'],(int)$row['dream_trip_id'],(int)$row['id'],'started'))return [false,'This Next Move has already been started automatically once; automatic retry is disabled.'];$usage=$this->usage((int)$row['user_id'],(int)$row['dream_trip_id']);if((int)$usage['started']>=(int)$policy['max_auto_starts_per_day'])return [false,'Daily automatic research-start limit reached.'];return [true,'Eligible for automatic specialist research.'];
    }

    private function canApply(array $row,array $policy): array
    {
        $proposal=json_decode((string)($row['proposal_json']??''),true);if(!is_array($proposal))$proposal=[];$amount=isset($proposal['price'])&&is_numeric($proposal['price'])?max(0,(float)$proposal['price']):0.0;$type=(string)($row['proposal_type']??'');
        if(empty($policy['enabled'])||$policy['mode']!=='planning')return [false,'Planning autopilot is not enabled.',$amount,$proposal];if((int)$row['priority']<(int)$policy['minimum_priority'])return [false,'Proposal priority is below the saved autonomy threshold.',$amount,$proposal];
        $meta=json_decode((string)($row['action_metadata']??''),true);if(!is_array($meta))$meta=[];if(!in_array((string)($meta['source']??''),self::SAFE_SOURCES,true))return [false,'Only canonical Vacation Brain proactive/supervisor proposals can be auto-applied.',$amount,$proposal];
        $allowed=array_values(array_intersect(self::SAFE_PROPOSAL_TYPES,(array)$policy['allowed_proposal_types']));if(!in_array($type,$allowed,true))return [false,'This proposal type is not allowed by the standing policy.',$amount,$proposal];if(!in_array($type,self::SAFE_PROPOSAL_TYPES,true))return [false,'Provider and budget-decision proposal types always require explicit review.',$amount,$proposal];
        if(!empty($proposal['requires_external_confirmation']))return [false,'External/provider confirmation is required, so standing planning policy cannot apply it.',$amount,$proposal];if(trim((string)($proposal['source_url']??''))!=='')return [false,'A proposal carrying an external action URL cannot be auto-applied.',$amount,$proposal];if(strcasecmp(trim((string)($proposal['source_provider']??'Vacation Brain')),'Vacation Brain')!==0)return [false,'Only Vacation Brain-generated planning proposals can be auto-applied.',$amount,$proposal];if(!str_starts_with((string)($proposal['source_external_id']??''),'trip-action:'))return [false,'Proposal provenance is not the canonical Trip Action path.',$amount,$proposal];if(!in_array((string)($proposal['item_type']??'idea'),self::SAFE_ITEM_TYPES,true))return [false,'Flight, lodging, merchandise, and unknown item types are never auto-applied.',$amount,$proposal];
        if(!empty($policy['pause_on_verification_pending'])&&$this->verificationPending((int)$row['user_id'],(int)$row['dream_trip_id']))return [false,'A provider action is verification pending; planning autopilot is paused until provider state is verified.',$amount,$proposal];$usage=$this->usage((int)$row['user_id'],(int)$row['dream_trip_id']);if((int)$usage['applied']>=(int)$policy['max_auto_applies_per_day'])return [false,'Daily automatic planning-change limit reached.',$amount,$proposal];if($amount>(float)$policy['per_action_estimated_limit']+0.0001)return [false,'Estimated item value exceeds the per-action planning limit.',$amount,$proposal];if(((float)$usage['estimated_amount']+$amount)>(float)$policy['daily_estimated_limit']+0.0001)return [false,'Daily estimated planning-value limit would be exceeded.',$amount,$proposal];return [true,'Eligible under the saved non-transactional planning policy.',$amount,$proposal];
    }

    private function usage(int $userId,int $tripId): array
    {
        $out=['started'=>0,'applied'=>0,'estimated_amount'=>0.0];if(!$this->ready())return $out;$stmt=$this->pdo->prepare("SELECT decision_type,COUNT(*) total,COALESCE(SUM(CASE WHEN decision_type='applied' THEN estimated_amount ELSE 0 END),0) amount FROM trip_autonomy_decisions WHERE user_id=? AND dream_trip_id=? AND created_at>=CURDATE() AND decision_type IN ('started','applied') GROUP BY decision_type");$stmt->execute([$userId,$tripId]);foreach($stmt->fetchAll()?:[] as $row){$out[(string)$row['decision_type']]=(int)$row['total'];if((string)$row['decision_type']==='applied')$out['estimated_amount']=(float)$row['amount'];}return $out;
    }

    private function verificationPending(int $userId,int $tripId): bool
    {
        if(!db_table_exists('trip_booking_action_intents'))return false;$stmt=$this->pdo->prepare("SELECT 1 FROM trip_booking_action_intents WHERE user_id=? AND dream_trip_id=? AND status='verification_pending' LIMIT 1");$stmt->execute([$userId,$tripId]);return (bool)$stmt->fetchColumn();
    }

    private function hasDecision(int $userId,int $tripId,int $actionId,string $type): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM trip_autonomy_decisions WHERE user_id=? AND dream_trip_id=? AND action_id=? AND decision_type=? LIMIT 1');$stmt->execute([$userId,$tripId,$actionId,$type]);return (bool)$stmt->fetchColumn();
    }

    private function auditPayload(int $userId,int $tripId,int $actionId,?int $executionId,string $type,?string $proposalType,string $reason,?float $amount,array $policy,array $detail): array
    {
        return ['decision_key'=>$this->decisionKey($userId,$tripId,$actionId,$executionId,$type,$proposalType),'proposal_type'=>$proposalType,'reason'=>$reason,'estimated_amount'=>$amount,'policy'=>$this->publicPolicy($policy),'detail'=>$detail];
    }

    private function decisionKey(int $userId,int $tripId,int $actionId,?int $executionId,string $type,?string $proposalType): string
    {
        return hash('sha256',implode('|',[$userId,$tripId,$actionId,$executionId??0,$type,$proposalType??'']));
    }

    private function recordDecision(int $userId,int $tripId,?int $actionId,?int $executionId,string $type,?string $proposalType,string $reason,?float $amount,array $policy,array $detail=[]): void
    {
        $scope=$type==='blocked'?date('Y-m-d').'|'.$reason:$type;$key=hash('sha256',implode('|',[$userId,$tripId,$actionId??0,$executionId??0,$scope,$proposalType??'']));$sql='INSERT IGNORE INTO trip_autonomy_decisions (user_id,dream_trip_id,action_id,execution_id,decision_key,decision_type,proposal_type,reason,estimated_amount,policy_json,detail_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)';$this->pdo->prepare($sql)->execute([$userId,$tripId,$actionId,$executionId,$key,$type,$proposalType,$this->clip($reason,700),$amount,$this->json($this->publicPolicy($policy)),$this->json($detail)]);
    }

    private function notifyApplied(int $userId,int $tripId,int $executionId,array $proposal,array $policy): void
    {
        if(empty($policy['notifications_enabled']))return;try{(new NotificationService($this->pdo))->create($userId,'trip_autonomy','Vacation Brain applied a planning change',$this->clip((string)($proposal['title']??'A low-risk trip planning item was added automatically.'),500),app_url('autonomous-trip.php?id='.$tripId),null,null,'trip-autonomy-applied:'.$executionId);}catch(Throwable){}
    }

    private function policyFromRow(array $row): array
    {
        $types=json_decode((string)($row['allowed_proposal_types_json']??''),true);if(!is_array($types))$types=[];return ['enabled'=>!empty($row['enabled']),'mode'=>$this->mode((string)($row['autonomy_mode']??'observe')),'minimum_priority'=>(int)($row['minimum_priority']??90),'max_auto_starts_per_day'=>(int)($row['max_auto_starts_per_day']??3),'max_auto_applies_per_day'=>(int)($row['max_auto_applies_per_day']??2),'per_action_estimated_limit'=>(float)($row['per_action_estimated_limit']??0),'daily_estimated_limit'=>(float)($row['daily_estimated_limit']??0),'allowed_proposal_types'=>array_values(array_intersect(self::SAFE_PROPOSAL_TYPES,array_map('strval',$types))),'pause_on_verification_pending'=>!empty($row['pause_on_verification_pending']),'notifications_enabled'=>!empty($row['notifications_enabled'])];
    }

    private function publicPolicy(array $p): array
    {
        return ['enabled'=>(bool)($p['enabled']??false),'mode'=>(string)($p['mode']??'observe'),'minimum_priority'=>(int)($p['minimum_priority']??90),'max_auto_starts_per_day'=>(int)($p['max_auto_starts_per_day']??3),'max_auto_applies_per_day'=>(int)($p['max_auto_applies_per_day']??2),'per_action_estimated_limit'=>(float)($p['per_action_estimated_limit']??0),'daily_estimated_limit'=>(float)($p['daily_estimated_limit']??0),'allowed_proposal_types'=>array_values((array)($p['allowed_proposal_types']??[])),'pause_on_verification_pending'=>(bool)($p['pause_on_verification_pending']??true),'notifications_enabled'=>(bool)($p['notifications_enabled']??true)];
    }

    private function defaults(): array{return ['exists'=>false,'enabled'=>false,'mode'=>'observe','minimum_priority'=>90,'max_auto_starts_per_day'=>3,'max_auto_applies_per_day'=>2,'per_action_estimated_limit'=>0.0,'daily_estimated_limit'=>0.0,'allowed_proposal_types'=>['planning_task','itinerary_item'],'pause_on_verification_pending'=>true,'notifications_enabled'=>true,'updated_at'=>null];}

    private function withTripLock(int $tripId,callable $callback): mixed
    {
        $key='vb-autonomy:'.$tripId;$stmt=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$key]);if((int)$stmt->fetchColumn()!==1)throw new DomainException('Another autonomy worker is already evaluating this trip.');try{return $callback();}finally{try{$release=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$key]);}catch(Throwable){}}
    }

    private function assertTrip(int $userId,int $tripId): array
    {
        if($userId<1||$tripId<1)throw new OutOfBoundsException('Trip not found.');$trip=(new DreamService($this->pdo))->get($userId,$tripId,false);if(!$trip)throw new OutOfBoundsException('Trip not found.');return $trip;
    }
    private function mode(string $value): string{$value=strtolower(trim($value));return in_array($value,self::MODES,true)?$value:'observe';}
    private function money(mixed $value): float{$n=is_numeric($value)?(float)$value:0.0;return round(max(0,min(999999.99,$n)),2);}
    private function json(mixed $value): string{$json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);return $json===false?'{}':$json;}
    private function clip(string $value,int $max): string{$value=trim(preg_replace('/\s+/',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($value,0,$max):substr($value,0,$max);}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade to enable Autonomous Travel Operations.');}
}
