<?php
declare(strict_types=1);

/**
 * Live Trip Spend + Budget Execution.
 *
 * Extends the owner-only Trip Cost Intelligence ledger with spend pacing, receipt
 * references, reconciliation state and shared-trip attribution. It never connects to
 * banks, charges/refunds money, converts currency, or exposes private expense detail
 * to collaborators. All recovery suggestions are advisory and non-destructive.
 */
final class TripSpendExecutionService
{
    private const CATEGORIES=[
        'flight'=>'Flights','lodging'=>'Lodging','transport'=>'Transportation','food'=>'Food & dining',
        'activity'=>'Activities','event'=>'Events & tickets','shopping'=>'Shopping','fees'=>'Fees & misc. charges','other'=>'Other',
    ];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_spend_execution_settings')
            && db_table_exists('trip_spend_entry_meta')
            && db_table_exists('trip_spend_events')
            && db_table_exists('trip_cost_entries');
    }

    public function snapshot(int $userId,int $tripId): array
    {
        $access=$this->access($userId,$tripId);$trip=$this->trip($tripId);$owner=!empty($access['is_owner']);$role=(string)($access['role']??'viewer');
        if(!$this->ready())return ['ready'=>false,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>false,'execution'=>[],'entries'=>[],'attribution'=>[],'suggestions'=>[],'safety_note'=>$this->safetyNote()];
        if(!$owner){return ['ready'=>true,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>false,'execution'=>[],'entries'=>[],'attribution'=>$this->sharedAttributionCounts($tripId),'suggestions'=>[],'privacy_note'=>'Collaborators may see only shared attribution counts. Expense amounts, merchants, receipt references, notes, reconciliation state and owner budget pacing remain private to the trip owner.','safety_note'=>$this->safetyNote()];}
        $currency=$this->currency((string)($trip['currency']??'USD'));$settings=$this->settings($userId,$tripId);$entries=$this->entries($userId,$tripId);$execution=$this->execution($userId,$trip,$entries,$settings,$currency);
        return ['ready'=>true,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>true,'categories'=>self::CATEGORIES,'settings'=>$settings,'execution'=>$execution,'entries'=>$entries,'attribution'=>$this->attributionPeople($userId,$tripId),'bookings'=>$this->bookingOptions($tripId),'suggestions'=>$this->recoverySuggestions($execution),'privacy_note'=>'Live spend details are owner-only. Ordinary agent context receives aggregate totals and pacing only; merchant names, notes, receipt references, booking IDs and attributed traveler identities are excluded.','safety_note'=>$this->safetyNote()];
    }

    public function settings(int $userId,int $tripId): array
    {
        $this->requireOwner($userId,$tripId);$trip=$this->trip($tripId);$currency=$this->currency((string)($trip['currency']??'USD'));
        if(!$this->ready())return ['currency'=>$currency,'alert_spend_pct'=>85.0,'daily_allowance_override'=>null];
        $q=$this->pdo->prepare('SELECT currency,alert_spend_pct,daily_allowance_override FROM trip_spend_execution_settings WHERE user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$userId,$tripId]);$r=$q->fetch();
        return $r?['currency'=>$this->currency((string)$r['currency']),'alert_spend_pct'=>(float)$r['alert_spend_pct'],'daily_allowance_override'=>$r['daily_allowance_override']===null?null:(float)$r['daily_allowance_override']]:['currency'=>$currency,'alert_spend_pct'=>85.0,'daily_allowance_override'=>null];
    }

    public function saveSettings(int $userId,int $tripId,array $input): array
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$trip=$this->trip($tripId);$currency=$this->currency((string)($input['currency']??($trip['currency']??'USD')));$tripCurrency=$this->currency((string)($trip['currency']??'USD'));if($currency!==$tripCurrency)throw new InvalidArgumentException('Spend execution currency must match the trip currency. Vacation Brain does not guess exchange rates.');
        $pct=$this->number($input['alert_spend_pct']??85,25,100,'Alert threshold');$daily=$this->optionalMoney($input['daily_allowance_override']??null);
        $sql='INSERT INTO trip_spend_execution_settings (user_id,dream_trip_id,currency,alert_spend_pct,daily_allowance_override,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE currency=VALUES(currency),alert_spend_pct=VALUES(alert_spend_pct),daily_allowance_override=VALUES(daily_allowance_override),updated_by=VALUES(updated_by),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$userId,$tripId,$currency,$pct,$daily,$userId]);$this->event($userId,$tripId,'settings_saved',['currency'=>$currency,'alert_spend_pct'=>$pct,'daily_allowance_override'=>$daily]);return $this->settings($userId,$tripId);
    }

    public function captureExpense(int $userId,int $tripId,array $input): int
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$trip=$this->trip($tripId);$currency=$this->currency((string)($input['currency']??($trip['currency']??'USD')));if($currency!==$this->currency((string)($trip['currency']??'USD')))throw new InvalidArgumentException('Live spend capture uses the trip currency. Vacation Brain does not guess exchange rates.');
        $costs=new TripCostIntelligenceService($this->pdo);if(!$costs->ready())throw new RuntimeException('Trip Cost Intelligence must be upgraded before live spend can be captured.');
        $payload=$input;$payload['currency']=$currency;$id=$costs->addExpense($userId,$tripId,$payload);$this->saveMeta($userId,$tripId,$id,$input,false);$this->event($userId,$tripId,'expense_captured',['entry_id'=>$id,'category'=>(string)($input['cost_category']??''),'currency'=>$currency]);return $id;
    }

    public function updateEntryMeta(int $userId,int $tripId,int $entryId,array $input): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$this->ownedEntry($userId,$tripId,$entryId);$this->saveMeta($userId,$tripId,$entryId,$input,true);$this->event($userId,$tripId,'expense_meta_updated',['entry_id'=>$entryId]);
    }

    public function voidExpense(int $userId,int $tripId,int $entryId,string $reason): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$costs=new TripCostIntelligenceService($this->pdo);$costs->voidExpense($userId,$tripId,$entryId,$reason);$this->event($userId,$tripId,'expense_voided',['entry_id'=>$entryId]);
    }

    /** Owner-only aggregate context; never includes merchant, notes, receipt refs or traveler identity. */
    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limit=max(1,min(5,$limitTrips));$q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' ORDER BY FIELD(operational_state,'traveling','ready','booking','planning','completed'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limit}");$q->execute([$userId]);$out=[];
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $tripId){$tripId=(int)$tripId;try{$s=$this->snapshot($userId,$tripId);$e=$s['execution']??[];}catch(Throwable){continue;}if(!$e)continue;$out[]=['trip_id'=>$tripId,'trip_name'=>(string)($s['trip']['name']??''),'currency'=>(string)($e['currency']??'USD'),'actual_spend'=>(float)($e['actual_spend']??0),'target_budget'=>$e['target_budget'],'remaining_budget'=>$e['remaining_budget'],'spend_pct'=>$e['spend_pct'],'pace_status'=>(string)($e['pace_status']??'unknown'),'remaining_daily_allowance'=>$e['remaining_daily_allowance'],'planning_expected_final'=>$e['planning_expected_final'],'days_remaining'=>(int)($e['days_remaining']??0)];}
        return $out;
    }

    public function inboxProjection(int $userId,int $tripId): ?array
    {
        if(!$this->ready())return null;$a=$this->access($userId,$tripId);if(empty($a['is_owner']))return null;$s=$this->snapshot($userId,$tripId);$e=$s['execution']??[];$target=$e['target_budget']??null;$pct=$e['spend_pct']??null;if($target===null||$target<=0||$pct===null)return null;$threshold=(float)($s['settings']['alert_spend_pct']??85);if($pct<$threshold&&($e['pace_status']??'')!=='over_budget')return null;$over=max(0,(float)$e['actual_spend']-(float)$target);$status=$over>0?'over_budget':'spend_threshold';$priority=$over>0?95:($pct>=95?90:82);$body=$over>0?'Actual recorded spend is '.$this->moneyLabel((float)$e['actual_spend'],(string)$e['currency']).', '.$this->moneyLabel($over,(string)$e['currency']).' above the trip target.':'Actual recorded spend has reached '.number_format((float)$pct,1).'% of the trip target, with '.$this->moneyLabel((float)($e['remaining_budget']??0),(string)$e['currency']).' remaining.';
        return ['source_key'=>(string)$tripId,'thread_key'=>'spend-execution:'.$tripId,'item_type'=>'risk','priority'=>$priority,'urgency'=>($e['days_remaining']??99)<=1?'today':'before_trip','title'=>$over>0?'Trip actual spend is over budget':'Trip spend is approaching the budget limit','body'=>$body.' Recorded expenses are owner-entered facts, not bank-settled verification.','action_label'=>'Review live spend','action_url'=>app_url('trip-spend.php?id='.$tripId),'source_status'=>$status,'requires_action'=>true,'occurred_at'=>date('Y-m-d H:i:s')];
    }

    private function execution(int $userId,array $trip,array $entries,array $settings,string $currency): array
    {
        $actual=0.0;$cats=array_fill_keys(array_keys(self::CATEGORIES),0.0);$receipt=0;$unreconciled=0;
        foreach($entries as $r){if(!empty($r['voided_at'])||$this->currency((string)$r['currency'])!==$currency)continue;$amt=(float)$r['amount'];$actual+=$amt;$cat=(string)$r['cost_category'];if(isset($cats[$cat]))$cats[$cat]+=$amt;if(($r['receipt_status']??'none')==='captured')$receipt++;if(($r['reconciliation_status']??'unreconciled')==='unreconciled')$unreconciled++;}
        $target=$trip['target_budget']===null?null:(float)$trip['target_budget'];$remaining=$target===null?null:round($target-$actual,2);$spendPct=$target&&$target>0?round($actual/$target*100,1):null;$days=$this->dayProgress($trip);$daily=$settings['daily_allowance_override'];if($daily===null&&$remaining!==null)$daily=round(max(0,$remaining)/max(1,$days['remaining']),2);
        $plan=[];$q=$this->pdo->prepare('SELECT cost_category,amount,currency FROM trip_cost_category_plans WHERE dream_trip_id=? ORDER BY cost_category');$q->execute([(int)$trip['id']]);foreach($q->fetchAll()?:[] as $r){if($this->currency((string)$r['currency'])===$currency)$plan[(string)$r['cost_category']]=(float)$r['amount'];}
        $categoryRows=[];foreach(self::CATEGORIES as $key=>$label){$p=$plan[$key]??null;$a=round((float)$cats[$key],2);$categoryRows[$key]=['label'=>$label,'planned'=>$p,'actual'=>$a,'remaining'=>$p===null?null:round($p-$a,2),'over'=>$p!==null&&$a>$p];}
        $forecast=null;$risk='unknown';try{$aff=new TripAffordabilityService($this->pdo);if($aff->ready()){$as=$aff->snapshot($userId,(int)$trip['id']);$f=$as['forecast']??[];$forecast=$f['expected_final']??null;$risk=(string)($f['risk_status']??'unknown');}}catch(Throwable){}
        $pace='unknown';if($target!==null&&$target>0){if($actual>$target)$pace='over_budget';elseif($spendPct!==null&&$spendPct>=(float)$settings['alert_spend_pct'])$pace='watch';elseif($days['elapsed_ratio']>0&&($spendPct/100)>($days['elapsed_ratio']+0.12))$pace='ahead_of_pace';else $pace='within_pace';}
        return ['currency'=>$currency,'actual_spend'=>round($actual,2),'target_budget'=>$target,'remaining_budget'=>$remaining,'spend_pct'=>$spendPct,'pace_status'=>$pace,'days_total'=>$days['total'],'days_elapsed'=>$days['elapsed'],'days_remaining'=>$days['remaining'],'remaining_daily_allowance'=>$daily,'planning_expected_final'=>$forecast,'planning_risk_status'=>$risk,'receipt_count'=>$receipt,'unreconciled_count'=>$unreconciled,'categories'=>$categoryRows,'semantics'=>'Actual spend is the owner-entered non-void Trip Cost ledger in the trip currency. It is not bank settlement. Planning expected final remains a forecast and is shown separately to avoid false reconciliation.'];
    }

    private function recoverySuggestions(array $e): array
    {
        if(!in_array((string)($e['pace_status']??''),['watch','ahead_of_pace','over_budget'],true))return [];$need=max(0,-(float)($e['remaining_budget']??0));if($need<=0&&($e['target_budget']??0)>0){$pct=(float)($e['spend_pct']??0);$target=(float)$e['target_budget'];$need=max(0,$target*max(0,$pct-75)/100*0.35);}if($need<=0)$need=max(25,(float)($e['remaining_daily_allowance']??0)*0.15);
        $out=[];foreach(['shopping','activity','event','food','transport'] as $cat){$r=$e['categories'][$cat]??null;if(!$r)continue;$remaining=$r['remaining'];if($remaining===null||$remaining<=0)continue;$save=round(min((float)$remaining*.25,$need),2);if($save<=0)continue;$out[]=['category'=>$cat,'title'=>'Trim '.$r['label'].' remaining plan','estimated_savings'=>$save,'body'=>'Consider reducing the remaining '.$r['label'].' plan by about '.$this->moneyLabel($save,(string)$e['currency']).'. This is an advisory target only; Vacation Brain will not cancel, rebook or charge anything.'];$need=max(0,$need-$save);if(count($out)>=3)break;}
        if(!$out)$out[]=['category'=>'general','title'=>'Review discretionary spend before the next purchase','estimated_savings'=>0.0,'body'=>'The ledger is near or over its budget pace. Review non-essential purchases and unresolved expense entries before changing any fixed booking.'];return $out;
    }

    private function saveMeta(int $userId,int $tripId,int $entryId,array $input,bool $existing): void
    {
        $this->ownedEntry($userId,$tripId,$entryId);$receipt=strtolower(trim((string)($input['receipt_status']??'none')));if(!in_array($receipt,['none','captured','missing'],true))$receipt='none';$ref=$this->nullableClip((string)($input['receipt_reference']??''),255);$rec=strtolower(trim((string)($input['reconciliation_status']??'unreconciled')));if(!in_array($rec,['unreconciled','booking_linked','owner_confirmed','cash_confirmed','other_confirmed'],true))$rec='unreconciled';$scope=strtolower(trim((string)($input['shared_scope']??'private')));if(!in_array($scope,['private','trip_shared'],true))$scope='private';$attr=$this->attributedUserOrNull($tripId,(int)($input['attributed_user_id']??0));if($scope==='private')$attr=null;
        $sql='INSERT INTO trip_spend_entry_meta (trip_cost_entry_id,user_id,dream_trip_id,receipt_status,receipt_reference,reconciliation_status,shared_scope,attributed_user_id,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE receipt_status=VALUES(receipt_status),receipt_reference=VALUES(receipt_reference),reconciliation_status=VALUES(reconciliation_status),shared_scope=VALUES(shared_scope),attributed_user_id=VALUES(attributed_user_id),updated_by=VALUES(updated_by),updated_at=NOW()';$this->pdo->prepare($sql)->execute([$entryId,$userId,$tripId,$receipt,$ref,$rec,$scope,$attr,$userId]);
    }

    private function entries(int $userId,int $tripId): array
    {
        $q=$this->pdo->prepare("SELECT e.id,e.cost_category,e.amount,e.currency,e.merchant_name,e.source_booking_id,e.occurred_at,e.note,e.voided_at,e.void_reason,e.created_at,COALESCE(m.receipt_status,'none') receipt_status,m.receipt_reference,COALESCE(m.reconciliation_status,'unreconciled') reconciliation_status,COALESCE(m.shared_scope,'private') shared_scope,m.attributed_user_id FROM trip_cost_entries e LEFT JOIN trip_spend_entry_meta m ON m.trip_cost_entry_id=e.id WHERE e.user_id=? AND e.dream_trip_id=? ORDER BY COALESCE(e.occurred_at,e.created_at) DESC,e.id DESC LIMIT 300");$q->execute([$userId,$tripId]);return $q->fetchAll()?:[];
    }

    private function bookingOptions(int $tripId): array
    {
        if(!db_table_exists('trip_bookings'))return [];$q=$this->pdo->prepare("SELECT id,booking_type,title,provider,amount,currency,status FROM trip_bookings WHERE dream_trip_id=? AND status<>'cancelled' ORDER BY COALESCE(starts_at,created_at),id");$q->execute([$tripId]);return $q->fetchAll()?:[];
    }

    private function sharedAttributionCounts(int $tripId): array
    {
        $q=$this->pdo->prepare("SELECT attributed_user_id,COUNT(*) entry_count FROM trip_spend_entry_meta m JOIN trip_cost_entries e ON e.id=m.trip_cost_entry_id AND e.voided_at IS NULL WHERE m.dream_trip_id=? AND m.shared_scope='trip_shared' AND m.attributed_user_id IS NOT NULL GROUP BY attributed_user_id ORDER BY entry_count DESC,attributed_user_id");$q->execute([$tripId]);$out=[];foreach($q->fetchAll()?:[] as $r)$out[]=['user_id'=>(int)$r['attributed_user_id'],'entry_count'=>(int)$r['entry_count']];return $out;
    }

    private function attributionPeople(int $userId,int $tripId): array
    {
        try{if(class_exists('TripCollaborationService')){$c=new TripCollaborationService($this->pdo);$rows=$c->members($userId,$tripId);$out=[];foreach($rows as $r)$out[]=['user_id'=>(int)$r['user_id'],'display_name'=>(string)($r['display_name']?:$r['username']?:('Traveler #'.$r['user_id'])),'role'=>(string)$r['role']];return $out;}}catch(Throwable){}return [['user_id'=>$userId,'display_name'=>'Trip owner','role'=>'owner']];
    }

    private function attributedUserOrNull(int $tripId,int $uid): ?int
    {
        if($uid<1)return null;$q=$this->pdo->prepare("SELECT user_id FROM dream_trips WHERE id=? AND user_id=? UNION SELECT user_id FROM trip_collaborators WHERE dream_trip_id=? AND user_id=? AND status='active' LIMIT 1");$q->execute([$tripId,$uid,$tripId,$uid]);return $q->fetchColumn()?$uid:null;
    }

    private function ownedEntry(int $userId,int $tripId,int $entryId): array
    {
        $q=$this->pdo->prepare('SELECT id,voided_at FROM trip_cost_entries WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$entryId,$userId,$tripId]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Trip expense not found.');return $r;
    }

    private function dayProgress(array $trip): array
    {
        $start=strtotime((string)($trip['start_date']??''));$end=strtotime((string)($trip['end_date']??''));$today=strtotime(date('Y-m-d'));if(!$start||!$end||$end<$start)return ['total'=>1,'elapsed'=>0,'remaining'=>1,'elapsed_ratio'=>0.0];$s=strtotime(date('Y-m-d',$start));$e=strtotime(date('Y-m-d',$end));$total=max(1,(int)floor(($e-$s)/86400)+1);if($today<$s)return ['total'=>$total,'elapsed'=>0,'remaining'=>$total,'elapsed_ratio'=>0.0];if($today>$e)return ['total'=>$total,'elapsed'=>$total,'remaining'=>0,'elapsed_ratio'=>1.0];$elapsed=max(1,(int)floor(($today-$s)/86400)+1);$remaining=max(1,$total-$elapsed+1);return ['total'=>$total,'elapsed'=>$elapsed,'remaining'=>$remaining,'elapsed_ratio'=>$elapsed/$total];
    }

    private function access(int $userId,int $tripId): array
    {
        if(class_exists('TripCollaborationService')){$c=new TripCollaborationService($this->pdo);$a=$c->access($userId,$tripId);if($a)return $a;}$q=$this->pdo->prepare('SELECT user_id FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);$owner=(int)($q->fetchColumn()?:0);if($owner===$userId)return ['role'=>'owner','is_owner'=>true,'can_view'=>true];throw new OutOfBoundsException('Trip not found or permission denied.');
    }
    private function requireOwner(int $userId,int $tripId): void{$a=$this->access($userId,$tripId);if(empty($a['is_owner']))throw new OutOfBoundsException('Only the trip owner can manage live spend and private receipt/reconciliation data.');}
    private function trip(int $tripId): array{$q=$this->pdo->prepare('SELECT id,user_id,name,status,operational_state,start_date,end_date,travelers,target_budget,currency,metadata_json FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Trip not found.');return $r;}
    private function publicTrip(array $t): array{return ['id'=>(int)$t['id'],'name'=>(string)$t['name'],'status'=>(string)$t['status'],'operational_state'=>(string)($t['operational_state']??''),'start_date'=>$t['start_date'],'end_date'=>$t['end_date'],'travelers'=>(int)($t['travelers']??1),'target_budget'=>$t['target_budget']===null?null:(float)$t['target_budget'],'currency'=>(string)($t['currency']??'USD')];}
    private function event(int $userId,int $tripId,string $type,array $data): void{$this->pdo->prepare('INSERT INTO trip_spend_events (user_id,dream_trip_id,event_type,event_json) VALUES (?,?,?,?)')->execute([$userId,$tripId,$type,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade through migration 061 for Live Trip Spend + Budget Execution.');}
    private function currency(string $v): string{$v=strtoupper(trim($v));if(!preg_match('/^[A-Z]{3}$/',$v))throw new InvalidArgumentException('Currency must be a 3-letter code.');return $v;}
    private function number(mixed $v,float $min,float $max,string $label): float{if(!is_numeric($v))throw new InvalidArgumentException($label.' must be numeric.');$n=(float)$v;if($n<$min||$n>$max)throw new InvalidArgumentException($label.' is outside the allowed range.');return round($n,2);}
    private function optionalMoney(mixed $v): ?float{$s=trim((string)$v);if($s==='')return null;if(!is_numeric($s)||(float)$s<0)throw new InvalidArgumentException('Daily allowance must be zero or greater.');return round((float)$s,2);}
    private function nullableClip(string $v,int $max): ?string{$v=trim($v);if($v==='')return null;return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
    private function moneyLabel(float $v,string $c): string{return ($c==='USD'?'$':$c.' ').number_format($v,2);}
    private function safetyNote(): string{return 'Live Trip Spend is an owner-entered planning and reconciliation ledger, not a bank feed. It never stores payment credentials, moves money, guesses exchange rates, or performs a booking, cancellation, refund, claim or purchase.';}
}
