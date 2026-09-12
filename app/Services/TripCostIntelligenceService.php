<?php
declare(strict_types=1);

/**
 * Trip Cost Intelligence + True Trip Economics.
 *
 * Combines shared target/category budgets and canonical booking values with owner-only
 * manual expenses, Trip Memory reported actual spend, and Disruption Resolution
 * costs/recoveries. Currency buckets are always kept separate; there is no hidden FX.
 * This service never charges, refunds, books, cancels, contacts providers, or moves money.
 */
final class TripCostIntelligenceService
{
    private const CATEGORIES=[
        'flight'=>'Flights','lodging'=>'Lodging','transport'=>'Transportation','food'=>'Food & dining',
        'activity'=>'Activities','event'=>'Events & tickets','shopping'=>'Shopping','fees'=>'Fees & misc. charges','other'=>'Other',
    ];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_cost_category_plans')
            && db_table_exists('trip_cost_entries')
            && db_table_exists('trip_cost_events');
    }

    public function categoryCatalog(): array{return self::CATEGORIES;}

    public function snapshot(int $userId,int $tripId): array
    {
        $access=$this->access($userId,$tripId);$trip=$this->trip($tripId);$role=(string)($access['role']??'viewer');
        if(!$this->ready())return $this->emptySnapshot($trip,$role,!empty($access['is_owner']),!empty($access['can_plan']));
        $owner=!empty($access['is_owner']);$canPlan=!empty($access['can_plan']);$bookings=$this->bookingRows($tripId);$bookingAgg=$this->aggregateBookings($bookings);$plans=$canPlan?$this->planRows($tripId):[];
        $bookingView=['by_currency'=>$bookingAgg['by_currency']??[],'count'=>count((array)($bookingAgg['rows']??[])),'semantics'=>(string)($bookingAgg['semantics']??'Saved booking value; not verified bank settlement.')];
        $shared=['plans'=>$plans,'bookings'=>$bookingView,'target_budget'=>$canPlan&&$trip['target_budget']!==null?(float)$trip['target_budget']:null,'currency'=>(string)($trip['currency']??'USD')];
        if(!$owner)return ['ready'=>true,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>false,'can_view_shared_budget'=>$canPlan,'can_view_private_costs'=>false,'categories'=>self::CATEGORIES,'shared'=>$shared,'economics'=>[],'manual_entries'=>[],'history_model'=>[],'privacy_note'=>'Shared collaborators receive only aggregate booking values and the budget view allowed by their trip role. Manual expenses, Trip Memory actual spend, disruption reimbursements and learned cost history are owner-only.','safety_note'=>$this->safetyNote()];
        $economics=$this->ownerEconomics($userId,$trip,$bookings,$plans,true);
        return ['ready'=>true,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>true,'can_view_shared_budget'=>true,'can_view_private_costs'=>true,'categories'=>self::CATEGORIES,'shared'=>$shared,'economics'=>$economics,'manual_entries'=>$this->manualRows($userId,$tripId),'history_model'=>$this->historyModel($userId,40),'privacy_note'=>'Private expense notes, merchant names and Trip Memory actual spend stay owner-only and are excluded from collaborator views. Ordinary agent context receives aggregate totals only, never notes, merchant names, confirmation codes or payment data.','safety_note'=>$this->safetyNote()];
    }

    public function saveCategoryPlan(int $userId,int $tripId,array $input): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$category=$this->category((string)($input['cost_category']??''));$currency=$this->currency((string)($input['currency']??'USD'));$amount=$this->moneyAllowZero($input['amount']??null);
        if($amount===0.0){$this->pdo->prepare('DELETE FROM trip_cost_category_plans WHERE user_id=? AND dream_trip_id=? AND cost_category=? AND currency=?')->execute([$userId,$tripId,$category,$currency]);$this->event($userId,$tripId,'category_plan_cleared',['category'=>$category,'currency'=>$currency]);return;}
        $sql='INSERT INTO trip_cost_category_plans (user_id,dream_trip_id,cost_category,amount,currency,updated_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),amount=VALUES(amount),updated_by=VALUES(updated_by),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([$userId,$tripId,$category,$amount,$currency,$userId]);$this->event($userId,$tripId,'category_plan_saved',['category'=>$category,'currency'=>$currency]);
    }

    public function addExpense(int $userId,int $tripId,array $input): int
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$category=$this->category((string)($input['cost_category']??''));$amount=$this->moneyPositive($input['amount']??null);$currency=$this->currency((string)($input['currency']??'USD'));
        $merchant=$this->nullableClip((string)($input['merchant_name']??''),180);$occurred=$this->dateTimeOrNull((string)($input['occurred_at']??''));$note=$this->nullableClip((string)($input['note']??''),1200);$bookingId=$this->ownedBookingOrNull($userId,$tripId,(int)($input['source_booking_id']??0));
        $this->pdo->prepare('INSERT INTO trip_cost_entries (user_id,dream_trip_id,cost_category,amount,currency,merchant_name,source_booking_id,occurred_at,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$userId,$tripId,$category,$amount,$currency,$merchant,$bookingId,$occurred,$note,$userId]);
        $id=(int)$this->pdo->lastInsertId();$this->event($userId,$tripId,'expense_added',['entry_id'=>$id,'category'=>$category,'currency'=>$currency]);return $id;
    }

    public function voidExpense(int $userId,int $tripId,int $entryId,string $reason): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$reason=$this->clip($reason,500);if($reason==='')throw new InvalidArgumentException('Add a reason for voiding this expense.');
        $q=$this->pdo->prepare('SELECT id,voided_at FROM trip_cost_entries WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$entryId,$userId,$tripId]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Trip expense not found.');if(!empty($row['voided_at']))throw new DomainException('This expense is already voided.');
        $u=$this->pdo->prepare('UPDATE trip_cost_entries SET voided_at=NOW(),voided_by=?,void_reason=? WHERE id=? AND user_id=? AND dream_trip_id=? AND voided_at IS NULL');$u->execute([$userId,$reason,$entryId,$userId,$tripId]);if($u->rowCount()!==1)throw new DomainException('This expense changed before the correction could be recorded. Refresh and try again.');
        $this->event($userId,$tripId,'expense_voided',['entry_id'=>$entryId]);
    }

    /** Completed learning-enabled Trip Memories become the factual cost-history sample set. */
    public function historyModel(int $userId,int $limit=40): array
    {
        if(!$this->ready()||!db_table_exists('trip_memories'))return ['trip_count'=>0,'economics_samples'=>0,'currencies'=>[]];$limit=max(1,min(80,$limit));
        $q=$this->pdo->prepare("SELECT m.dream_trip_id FROM trip_memories m JOIN dream_trips dt ON dt.id=m.dream_trip_id AND dt.user_id=m.user_id WHERE m.user_id=? AND m.status='complete' AND m.learning_enabled=1 ORDER BY COALESCE(m.completed_at,dt.end_date,dt.updated_at) DESC,m.id DESC LIMIT {$limit}");$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);$groups=[];$samples=0;
        foreach($ids as $tripId){try{$trip=$this->trip($tripId);$econ=$this->ownerEconomics($userId,$trip,$this->bookingRows($tripId),$this->planRows($tripId),false);}catch(Throwable){continue;}foreach((array)($econ['by_currency']??[]) as $currency=>$row){$basis=$row['comparison_basis']??null;if($basis===null||(float)$basis<=0)continue;$samples++;$groups[$currency]??=['samples'=>0,'total_cost'=>0.0,'total_budget_variance_pct'=>0.0,'budget_samples'=>0,'total_per_day'=>0.0,'per_day_samples'=>0,'total_per_traveler_day'=>0.0,'ptd_samples'=>0,'disruption_cost'=>0.0,'cash_recovered'=>0.0,'gross_cost'=>0.0,'category_gross'=>[]];$g=&$groups[$currency];$g['samples']++;$g['total_cost']+=(float)$basis;if($row['budget_variance_pct']!==null){$g['total_budget_variance_pct']+=(float)$row['budget_variance_pct'];$g['budget_samples']++;}if($row['per_day']!==null){$g['total_per_day']+=(float)$row['per_day'];$g['per_day_samples']++;}if($row['per_traveler_day']!==null){$g['total_per_traveler_day']+=(float)$row['per_traveler_day'];$g['ptd_samples']++;}$g['disruption_cost']+=(float)$row['disruption_cost'];$g['cash_recovered']+=(float)$row['cash_recovered'];$g['gross_cost']+=(float)$row['structured_gross_cost'];foreach((array)($econ['categories'][$currency]??[]) as $cat=>$c)$g['category_gross'][$cat]=($g['category_gross'][$cat]??0)+(float)($c['gross_cost']??0);unset($g);}}
        $out=[];foreach($groups as $currency=>$g){$cats=[];$den=max(.01,array_sum($g['category_gross']));foreach($g['category_gross'] as $cat=>$amount)$cats[]=['category'=>$cat,'label'=>self::CATEGORIES[$cat]??ucwords($cat),'share_pct'=>round($amount/$den*100,1),'average_per_trip'=>round($amount/max(1,$g['samples']),2)];usort($cats,static fn($a,$b)=>$b['share_pct']<=>$a['share_pct']);$out[$currency]=['samples'=>$g['samples'],'average_trip_cost'=>round($g['total_cost']/max(1,$g['samples']),2),'average_budget_variance_pct'=>$g['budget_samples']?round($g['total_budget_variance_pct']/$g['budget_samples'],1):null,'average_per_day'=>$g['per_day_samples']?round($g['total_per_day']/$g['per_day_samples'],2):null,'average_per_traveler_day'=>$g['ptd_samples']?round($g['total_per_traveler_day']/$g['ptd_samples'],2):null,'disruption_overhead_pct'=>$g['gross_cost']>0?round($g['disruption_cost']/$g['gross_cost']*100,1):0.0,'cash_recovery_rate_pct'=>$g['gross_cost']>0?round($g['cash_recovered']/$g['gross_cost']*100,1):0.0,'top_categories'=>array_slice($cats,0,6)];}
        return ['trip_count'=>count($ids),'economics_samples'=>$samples,'currencies'=>$out,'learning_note'=>'Cost history uses completed Trip Memories with learning enabled. Currencies stay separate and no exchange rate is guessed.'];
    }

    /** Safe aggregate context only; no manual notes, merchant names or private resolution evidence. */
    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limitTrips=max(1,min(5,$limitTrips));$ids=[];$q=$this->pdo->prepare("SELECT id FROM dream_trips WHERE user_id=? AND status<>'abandoned' ORDER BY FIELD(operational_state,'traveling','ready','booking','planning','completed'),COALESCE(start_date,'9999-12-31'),id LIMIT {$limitTrips}");$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if(count($ids)<$limitTrips&&db_table_exists('trip_collaborators')){$remain=$limitTrips-count($ids);$s=$this->pdo->prepare("SELECT tc.dream_trip_id FROM trip_collaborators tc JOIN dream_trips dt ON dt.id=tc.dream_trip_id WHERE tc.user_id=? AND tc.status='active' AND dt.status<>'abandoned' ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.id LIMIT {$remain}");$s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ids[]=(int)$id;}
        $out=[];foreach(array_values(array_unique($ids)) as $tripId){try{$access=$this->access($userId,$tripId);$trip=$this->trip($tripId);$bookings=$this->bookingRows($tripId);$book=$this->aggregateBookings($bookings);}catch(Throwable){continue;}$item=['trip_id'=>$tripId,'trip_name'=>(string)$trip['name'],'role'=>(string)($access['role']??'viewer'),'currency'=>(string)($trip['currency']??'USD'),'booking_totals'=>$book['by_currency']??[]];if(!empty($access['can_plan']))$item['target_budget']=$trip['target_budget']!==null?(float)$trip['target_budget']:null;if(!empty($access['is_owner'])){$e=$this->ownerEconomics($userId,$trip,$bookings,$this->planRows($tripId),false);$item['economics']=$e['by_currency']??[];}else $item['economics']=[];$out[]=$item;}
        return $out;
    }

    private function ownerEconomics(int $userId,array $trip,array $bookings,array $plans,bool $withForecast): array
    {
        $tripId=(int)$trip['id'];$days=$this->tripDays($trip);$travelers=max(1,(int)($trip['travelers']??1));$bookingAgg=$this->aggregateBookings($bookings);$countedBookingIds=array_fill_keys(array_map('intval',$bookingAgg['counted_booking_ids']??[]),true);$manual=$this->manualRows($userId,$tripId);$resolution=$this->resolutionRows($tripId);$memory=$this->memoryActual($userId,$tripId);$by=[];$cats=[];
        $ensure=function(string $currency,string $category) use (&$by,&$cats):void{$by[$currency]??=$this->emptyCurrencyRow();$cats[$currency]??=[];$cats[$currency][$category]??=$this->emptyCategoryRow();};
        foreach($plans as $r){$c=$this->currency((string)$r['currency']);$cat=$this->category((string)$r['cost_category']);$ensure($c,$cat);$cats[$c][$cat]['planned']+=(float)$r['amount'];$by[$c]['category_plan']+=(float)$r['amount'];}
        foreach((array)($bookingAgg['rows']??[]) as $r){$c=$this->currency((string)$r['currency']);$cat=$this->bookingCategory((string)$r['booking_type']);$ensure($c,$cat);$cats[$c][$cat]['booking_value']+=(float)$r['amount'];$by[$c]['booking_value']+=(float)$r['amount'];}
        foreach($manual as $r){if(!empty($r['voided_at']))continue;$c=$this->currency((string)$r['currency']);$cat=$this->category((string)$r['cost_category']);$ensure($c,$cat);$duplicate=!empty($r['source_booking_id'])&&isset($countedBookingIds[(int)$r['source_booking_id']]);if(!$duplicate){$cats[$c][$cat]['manual_cost']+=(float)$r['amount'];$by[$c]['manual_cost']+=(float)$r['amount'];}else{$cats[$c][$cat]['linked_cost_reference']+=(float)$r['amount'];}}
        foreach($resolution as $r){if(!empty($r['voided_at']))continue;$c=$this->currency((string)$r['currency']);$cat=$this->bookingCategory((string)($r['booking_type']??'other'));$ensure($c,$cat);$amt=(float)$r['amount'];$type=(string)$r['entry_type'];$duplicate=!empty($r['source_booking_id'])&&isset($countedBookingIds[(int)$r['source_booking_id']]);if(in_array($type,['replacement_cost','extra_expense'],true)){if(!$duplicate){$cats[$c][$cat]['disruption_cost']+=$amt;$by[$c]['disruption_cost']+=$amt;}else{$cats[$c][$cat]['linked_disruption_reference']+=$amt;}}elseif(in_array($type,['refund_expected','insurance_expected'],true)){$cats[$c][$cat]['cash_expected']+=$amt;$by[$c]['cash_expected']+=$amt;}elseif(in_array($type,['refund_received','insurance_received','other_recovery'],true)){$cats[$c][$cat]['cash_recovered']+=$amt;$by[$c]['cash_recovered']+=$amt;}elseif($type==='credit_expected'){$cats[$c][$cat]['credits_expected']+=$amt;$by[$c]['credits_expected']+=$amt;}elseif($type==='credit_received'){$cats[$c][$cat]['credits_received']+=$amt;$by[$c]['credits_received']+=$amt;}elseif($type==='writeoff'){$cats[$c][$cat]['written_off']+=$amt;$by[$c]['written_off']+=$amt;}}
        $tripCurrency=$this->currency((string)($trip['currency']??'USD'));$by[$tripCurrency]??=$this->emptyCurrencyRow();if($trip['target_budget']!==null)$by[$tripCurrency]['target_budget']=(float)$trip['target_budget'];if($memory){$mc=$this->currency((string)$memory['currency']);$by[$mc]??=$this->emptyCurrencyRow();$by[$mc]['reported_actual']=(float)$memory['actual_spend'];}
        foreach($by as &$row){$row['structured_gross_cost']=round($row['booking_value']+$row['manual_cost']+$row['disruption_cost'],2);$row['cash_outstanding']=round(max(0,$row['cash_expected']-$row['cash_recovered']-$row['written_off']),2);$row['credit_outstanding']=round(max(0,$row['credits_expected']-$row['credits_received']),2);$row['structured_net_cost']=round($row['structured_gross_cost']-$row['cash_recovered'],2);$row['comparison_basis']=$row['reported_actual']!==null?round((float)$row['reported_actual'],2):$row['structured_net_cost'];$row['comparison_basis_source']=$row['reported_actual']!==null?'traveler_reported_actual':'structured_net_cost';if($row['target_budget']!==null){$row['budget_variance']=round($row['comparison_basis']-(float)$row['target_budget'],2);$row['budget_variance_pct']=(float)$row['target_budget']>0?round($row['budget_variance']/(float)$row['target_budget']*100,1):null;}else{$row['budget_variance']=null;$row['budget_variance_pct']=null;}$row['per_day']=round($row['comparison_basis']/max(1,$days),2);$row['per_traveler']=round($row['comparison_basis']/$travelers,2);$row['per_traveler_day']=round($row['comparison_basis']/max(1,$days*$travelers),2);$row['structured_coverage_pct']=$row['reported_actual']!==null&&(float)$row['reported_actual']>0?round(min(100,$row['structured_gross_cost']/(float)$row['reported_actual']*100),1):null;}unset($row);
        foreach($cats as &$currencyCats)foreach($currencyCats as &$r){$r['gross_cost']=round($r['booking_value']+$r['manual_cost']+$r['disruption_cost'],2);$r['cash_outstanding']=round(max(0,$r['cash_expected']-$r['cash_recovered']-$r['written_off']),2);$r['credit_outstanding']=round(max(0,$r['credits_expected']-$r['credits_received']),2);$r['structured_net_cost']=round($r['gross_cost']-$r['cash_recovered'],2);}unset($r,$currencyCats);
        $forecast=$withForecast?$this->forecastFromModel($this->historyModelShallow($userId,25,$tripId),$tripCurrency,$days,$travelers):null;
        return ['by_currency'=>$by,'categories'=>$cats,'trip_days'=>$days,'travelers'=>$travelers,'forecast'=>$forecast,'memory_actual'=>$memory,'basis_note'=>'Budget variance uses traveler-reported Trip Memory actual spend when available; otherwise it uses structured net cost. Canonical booking amounts are saved booking values, not verified bank-settled cash. Provider credits remain non-cash and never reduce structured net cost.','currency_note'=>'Each currency is shown independently. Vacation Brain does not guess exchange rates.'];
    }

    private function historyModelShallow(int $userId,int $limit,int $excludeTripId): array
    {
        if(!db_table_exists('trip_memories'))return ['currencies'=>[]];$q=$this->pdo->prepare("SELECT m.actual_spend,m.currency,dt.start_date,dt.end_date,dt.travelers FROM trip_memories m JOIN dream_trips dt ON dt.id=m.dream_trip_id AND dt.user_id=m.user_id WHERE m.user_id=? AND m.status='complete' AND m.learning_enabled=1 AND m.dream_trip_id<>? AND m.actual_spend IS NOT NULL ORDER BY COALESCE(m.completed_at,dt.end_date,dt.updated_at) DESC LIMIT {$limit}");$q->execute([$userId,$excludeTripId]);$g=[];foreach($q->fetchAll()?:[] as $r){$c=$this->currency((string)$r['currency']);$days=$this->daysFromDates($r['start_date']??null,$r['end_date']??null);$trav=max(1,(int)($r['travelers']??1));$amt=(float)$r['actual_spend'];$g[$c]??=['samples'=>0,'total'=>0.0,'ptd'=>0.0];$g[$c]['samples']++;$g[$c]['total']+=$amt;$g[$c]['ptd']+=$amt/max(1,$days*$trav);}foreach($g as &$r){$r['average_trip_cost']=round($r['total']/max(1,$r['samples']),2);$r['average_per_traveler_day']=round($r['ptd']/max(1,$r['samples']),2);}unset($r);return ['currencies'=>$g];
    }

    private function forecastFromModel(array $model,string $currency,int $days,int $travelers): ?array
    {
        $m=$model['currencies'][$currency]??null;if(!$m||($m['samples']??0)<1)return null;$ptd=(float)($m['average_per_traveler_day']??0);return ['currency'=>$currency,'samples'=>(int)$m['samples'],'average_per_traveler_day'=>$ptd,'estimated_trip_cost'=>round($ptd*max(1,$days)*max(1,$travelers),2),'note'=>'Historical estimate from completed learning-enabled trips; it is not a quote or provider price.'];
    }

    private function aggregateBookings(array $rows): array
    {
        $by=[];$included=[];$safe=[];foreach($rows as $r){if($r['amount']===null||in_array((string)$r['status'],['unbooked','ready_to_book','cancelled'],true)||(string)$r['payment_status']==='refunded')continue;$c=$this->currency((string)$r['currency']);$amt=(float)$r['amount'];$by[$c]=($by[$c]??0)+$amt;$included[]=(int)$r['id'];$safe[]=['id'=>(int)$r['id'],'booking_type'=>(string)$r['booking_type'],'title'=>(string)$r['title'],'status'=>(string)$r['status'],'payment_status'=>(string)$r['payment_status'],'amount'=>$amt,'currency'=>$c];}foreach($by as &$v)$v=round($v,2);unset($v);return ['by_currency'=>$by,'counted_booking_ids'=>$included,'rows'=>$safe,'semantics'=>'Saved booking value; payment status does not prove exact cash settlement.'];
    }

    private function bookingRows(int $tripId): array{if(!db_table_exists('trip_bookings'))return [];$q=$this->pdo->prepare('SELECT id,booking_type,title,status,payment_status,amount,currency FROM trip_bookings WHERE dream_trip_id=? ORDER BY id');$q->execute([$tripId]);return $q->fetchAll()?:[];}
    private function planRows(int $tripId): array{$q=$this->pdo->prepare("SELECT id,cost_category,amount,currency,updated_at FROM trip_cost_category_plans WHERE dream_trip_id=? ORDER BY currency,FIELD(cost_category,'flight','lodging','transport','food','activity','event','shopping','fees','other')");$q->execute([$tripId]);return $q->fetchAll()?:[];}
    private function manualRows(int $userId,int $tripId): array{$q=$this->pdo->prepare('SELECT id,cost_category,amount,currency,merchant_name,source_booking_id,occurred_at,note,created_at,voided_at,void_reason FROM trip_cost_entries WHERE user_id=? AND dream_trip_id=? ORDER BY COALESCE(occurred_at,created_at) DESC,id DESC LIMIT 250');$q->execute([$userId,$tripId]);return $q->fetchAll()?:[];}
    private function resolutionRows(int $tripId): array{if(!db_table_exists('trip_resolution_entries'))return [];$q=$this->pdo->prepare("SELECT re.entry_type,re.amount,re.currency,re.source_booking_id,re.voided_at,COALESCE(tb.booking_type,'other') booking_type FROM trip_resolution_entries re LEFT JOIN trip_bookings tb ON tb.id=re.source_booking_id AND tb.dream_trip_id=re.dream_trip_id WHERE re.dream_trip_id=? ORDER BY re.id");$q->execute([$tripId]);return $q->fetchAll()?:[];}
    private function memoryActual(int $userId,int $tripId): ?array{if(!db_table_exists('trip_memories'))return null;$q=$this->pdo->prepare('SELECT actual_spend,currency,status,learning_enabled FROM trip_memories WHERE user_id=? AND dream_trip_id=? AND actual_spend IS NOT NULL LIMIT 1');$q->execute([$userId,$tripId]);$r=$q->fetch();return $r?['actual_spend'=>(float)$r['actual_spend'],'currency'=>$this->currency((string)$r['currency']),'status'=>(string)$r['status'],'learning_enabled'=>(int)$r['learning_enabled']]:null;}

    private function emptyCurrencyRow(): array{return ['target_budget'=>null,'category_plan'=>0.0,'booking_value'=>0.0,'manual_cost'=>0.0,'disruption_cost'=>0.0,'structured_gross_cost'=>0.0,'cash_expected'=>0.0,'cash_recovered'=>0.0,'cash_outstanding'=>0.0,'credits_expected'=>0.0,'credits_received'=>0.0,'credit_outstanding'=>0.0,'written_off'=>0.0,'structured_net_cost'=>0.0,'reported_actual'=>null,'comparison_basis'=>null,'comparison_basis_source'=>'structured_net_cost','budget_variance'=>null,'budget_variance_pct'=>null,'per_day'=>null,'per_traveler'=>null,'per_traveler_day'=>null,'structured_coverage_pct'=>null];}
    private function emptyCategoryRow(): array{return ['planned'=>0.0,'booking_value'=>0.0,'manual_cost'=>0.0,'disruption_cost'=>0.0,'linked_cost_reference'=>0.0,'linked_disruption_reference'=>0.0,'gross_cost'=>0.0,'cash_expected'=>0.0,'cash_recovered'=>0.0,'cash_outstanding'=>0.0,'credits_expected'=>0.0,'credits_received'=>0.0,'credit_outstanding'=>0.0,'written_off'=>0.0,'structured_net_cost'=>0.0];}
    private function bookingCategory(string $type): string{return match($type){'flight'=>'flight','lodging'=>'lodging','transport'=>'transport','restaurant'=>'food','activity'=>'activity','event'=>'event',default=>'other'};}
    private function category(string $value): string{$v=strtolower(trim($value));if(!isset(self::CATEGORIES[$v]))throw new InvalidArgumentException('Choose a supported cost category.');return $v;}
    private function currency(string $value): string{$v=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$v)?$v:'USD';}
    private function tripDays(array $trip): int{return $this->daysFromDates($trip['start_date']??null,$trip['end_date']??null);}
    private function daysFromDates(mixed $start,mixed $end): int{$s=trim((string)$start);$e=trim((string)$end);if($s===''||$e==='')return 1;$a=strtotime($s);$b=strtotime($e);if(!$a||!$b||$b<$a)return 1;return max(1,(int)floor(($b-$a)/86400)+1);}
    private function moneyPositive(mixed $v): float{$n=$this->moneyAllowZero($v);if($n<=0)throw new InvalidArgumentException('Expense amount must be greater than zero.');return $n;}
    private function moneyAllowZero(mixed $v): float{if($v===null||trim((string)$v)===''||!is_numeric($v))throw new InvalidArgumentException('Enter a valid amount.');$n=round((float)$v,2);if($n<0||$n>99999999)throw new InvalidArgumentException('Amount is outside the supported range.');return $n;}
    private function ownedBookingOrNull(int $userId,int $tripId,int $bookingId): ?int{if($bookingId<1)return null;$q=$this->pdo->prepare('SELECT id FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$bookingId,$userId,$tripId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Selected booking does not belong to this trip.');return $bookingId;}
    private function dateTimeOrNull(string $value): ?string{$v=trim($value);if($v==='')return null;$ts=strtotime($v);if(!$ts)throw new InvalidArgumentException('Enter a valid expense date/time.');return date('Y-m-d H:i:s',$ts);}
    private function clip(string $v,int $max): string{$v=trim(preg_replace('/\s+/u',' ',$v)??$v);return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
    private function nullableClip(string $v,int $max): ?string{$x=$this->clip($v,$max);return $x===''?null:$x;}
    private function event(int $userId,int $tripId,string $type,array $payload): void{$json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);$this->pdo->prepare('INSERT INTO trip_cost_events (user_id,dream_trip_id,event_type,event_json) VALUES (?,?,?,?)')->execute([$userId,$tripId,$type,$json]);}
    private function access(int $userId,int $tripId): array{if(class_exists('TripItineraryIntelligenceService'))return (new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);$trip=$this->trip($tripId);if((int)$trip['user_id']===$userId)return ['role'=>'owner','is_owner'=>true,'can_plan'=>true];throw new OutOfBoundsException('Trip not found or permission denied.');}
    private function requireOwner(int $userId,int $tripId): void{$a=$this->access($userId,$tripId);if(empty($a['is_owner']))throw new DomainException('Only the trip owner can manage actual expenses and category budgets.');}
    private function trip(int $tripId): array{$q=$this->pdo->prepare('SELECT * FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);$r=$q->fetch();if(!$r)throw new OutOfBoundsException('Trip not found.');return $r;}
    private function publicTrip(array $t): array{return ['id'=>(int)$t['id'],'name'=>(string)$t['name'],'currency'=>$this->currency((string)($t['currency']??'USD')),'start_date'=>$t['start_date']??null,'end_date'=>$t['end_date']??null,'travelers'=>max(1,(int)($t['travelers']??1)),'operational_state'=>(string)($t['operational_state']??'planning')];}
    private function emptySnapshot(array $trip,string $role,bool $owner,bool $canPlan): array{return ['ready'=>false,'trip'=>$this->publicTrip($trip),'role'=>$role,'can_manage'=>$owner,'can_view_shared_budget'=>$canPlan,'can_view_private_costs'=>$owner,'categories'=>self::CATEGORIES,'shared'=>[],'economics'=>[],'manual_entries'=>[],'history_model'=>[],'privacy_note'=>'Run System Upgrade for v1.51.','safety_note'=>$this->safetyNote()];}
    private function safetyNote(): string{return 'Cost Intelligence is analysis and bookkeeping only. It never charges a card, pays a provider, requests a refund, redeems a credit, books, cancels, or performs any provider transaction.';}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Trip Cost Intelligence + True Trip Economics.');}
}
