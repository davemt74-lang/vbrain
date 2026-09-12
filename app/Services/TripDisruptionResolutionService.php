<?php
declare(strict_types=1);

/**
 * Owner-controlled disruption resolution and refund/credit reconciliation.
 *
 * This service is an accounting/attention layer around canonical Recovery Intelligence.
 * It records user-entered expectations, received refunds/credits, extra costs, deadlines,
 * follow-ups and evidence metadata. It never submits a claim, requests a refund, issues a
 * credit, rebooks, cancels, pays, opens checkout or mutates a provider.
 */
final class TripDisruptionResolutionService
{
    private const CASE_STATUSES=['tracking','claim_needed','submitted','awaiting_provider','partially_recovered','resolved','closed_no_recovery'];
    private const ENTRY_TYPES=['replacement_cost','extra_expense','refund_expected','refund_received','credit_expected','credit_received','insurance_expected','insurance_received','other_recovery','writeoff'];
    private const EVIDENCE_TYPES=['booking','receipt','provider_message','policy','itinerary','note','other'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_resolution_cases')
            && db_table_exists('trip_resolution_entries')
            && db_table_exists('trip_resolution_evidence')
            && db_table_exists('trip_resolution_events')
            && db_column_exists('dream_trips','resolution_checked_at');
    }

    public function snapshot(int $userId,int $tripId,bool $sync=true): array
    {
        $access=$this->access($userId,$tripId);$trip=$this->trip($tripId);
        if(!$this->ready())return $this->emptySnapshot($trip,$access);
        if($sync&&!empty($access['is_owner']))$this->syncCases($userId,$tripId);
        $cases=$this->caseRows($tripId);$owner=!empty($access['is_owner']);
        foreach($cases as &$case){
            $case['attention']=$this->caseAttention($case);
            if($owner){
                $case['entries']=$this->entries((int)$case['id']);
                $case['evidence']=$this->evidence((int)$case['id']);
                $case['financials']=$this->aggregateEntries($case['entries']);
            }else{
                // Collaborators can see shared operational resolution state, but not
                // owner financial entries, evidence notes/URLs or resolution summary.
                $case['entries']=[];$case['evidence']=[];$case['financials']=[];
                $case['resolution_summary']=null;
            }
        }unset($case);
        if($owner)$this->syncInbox($userId,$tripId,$cases);
        $financials=$owner?$this->aggregateTrip($cases):[];
        $summary=[
            'total'=>count($cases),
            'open'=>count(array_filter($cases,static fn(array $c):bool=>!in_array((string)$c['status'],['resolved','closed_no_recovery'],true))),
            'claim_needed'=>count(array_filter($cases,static fn(array $c):bool=>(string)$c['status']==='claim_needed')),
            'awaiting'=>count(array_filter($cases,static fn(array $c):bool=>in_array((string)$c['status'],['submitted','awaiting_provider','partially_recovered'],true))),
            'overdue'=>count(array_filter($cases,static fn(array $c):bool=>!empty($c['attention']['deadline_overdue'])||!empty($c['attention']['followup_due']))),
        ];
        return [
            'ready'=>true,'generated_at'=>date(DATE_ATOM),'trip'=>$this->publicTrip($trip),'role'=>(string)($access['role']??'viewer'),
            'can_manage'=>$owner,'can_view_financials'=>$owner,'cases'=>$cases,'summary'=>$summary,'financials'=>$financials,
            'privacy_note'=>'Financial entries, evidence notes/URLs and resolution notes are owner-only. Ordinary agent context receives only status, deadlines and aggregate owner totals; no claim references, confirmation codes, payment data or private evidence text.',
            'safety_note'=>'Vacation Brain tracks recovery outcomes only. It does not submit claims, request or issue refunds/credits, move money, cancel, rebook, pay, or mutate provider reservations.',
        ];
    }

    public function saveCase(int $userId,int $tripId,int $caseId,array $input): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$case=$this->caseRow($tripId,$caseId);
        $status=strtolower(trim((string)($input['status']??$case['status'])));if(!in_array($status,self::CASE_STATUSES,true))throw new InvalidArgumentException('Unknown resolution status.');
        $provider=$this->nullableClip((string)($input['provider_name']??$case['provider_name']??''),180);
        $deadline=$this->dateTimeOrNull((string)($input['claim_deadline']??''));$followup=$this->dateTimeOrNull((string)($input['next_followup_at']??''));
        $summary=$this->nullableClip((string)($input['resolution_summary']??''),1200);$closed=in_array($status,['resolved','closed_no_recovery'],true)?date('Y-m-d H:i:s'):null;
        $before=(string)$case['status'];
        $this->pdo->prepare('UPDATE trip_resolution_cases SET status=?,provider_name=?,claim_deadline=?,next_followup_at=?,resolution_summary=?,closed_at=?,updated_by=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?')->execute([$status,$provider,$deadline,$followup,$summary,$closed,$userId,$caseId,$tripId]);
        if($before!==$status)$this->event($tripId,$caseId,$userId,'status_changed',['from'=>$before,'to'=>$status]);
        if($closed)$this->event($tripId,$caseId,$userId,'closed',['status'=>$status]);
        $this->syncInbox($userId,$tripId,$this->caseRows($tripId));
    }

    /** Append one financial fact. Corrections are recorded by voiding, never silent deletion. */
    public function addEntry(int $userId,int $tripId,int $caseId,array $input): int
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$this->caseRow($tripId,$caseId);
        $type=strtolower(trim((string)($input['entry_type']??'')));if(!in_array($type,self::ENTRY_TYPES,true))throw new InvalidArgumentException('Choose a supported resolution entry type.');
        $amount=$this->money($input['amount']??null);$currency=$this->currency((string)($input['currency']??'USD'));
        $provider=$this->nullableClip((string)($input['provider_name']??''),180);$bookingId=$this->ownedBookingOrNull($userId,$tripId,(int)($input['source_booking_id']??0));
        $occurred=$this->dateTimeOrNull((string)($input['occurred_at']??''));$note=$this->nullableClip((string)($input['note']??''),1200);
        $this->pdo->prepare('INSERT INTO trip_resolution_entries (dream_trip_id,resolution_case_id,entry_type,amount,currency,provider_name,source_booking_id,occurred_at,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$tripId,$caseId,$type,$amount,$currency,$provider,$bookingId,$occurred,$note,$userId]);
        $id=(int)$this->pdo->lastInsertId();$this->event($tripId,$caseId,$userId,'entry_added',['entry_id'=>$id,'entry_type'=>$type,'currency'=>$currency]);
        $this->reconcileCaseState($tripId,$caseId,$userId);return $id;
    }

    public function voidEntry(int $userId,int $tripId,int $caseId,int $entryId,string $reason): void
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$this->caseRow($tripId,$caseId);$reason=$this->clip($reason,500);if($reason==='')throw new InvalidArgumentException('Add a reason for voiding this ledger entry.');
        $q=$this->pdo->prepare('SELECT id,voided_at FROM trip_resolution_entries WHERE id=? AND dream_trip_id=? AND resolution_case_id=? LIMIT 1');$q->execute([$entryId,$tripId,$caseId]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Resolution entry not found.');if(!empty($row['voided_at']))throw new DomainException('This resolution entry is already voided.');
        $this->pdo->prepare('UPDATE trip_resolution_entries SET voided_at=NOW(),voided_by=?,void_reason=? WHERE id=? AND dream_trip_id=? AND resolution_case_id=? AND voided_at IS NULL')->execute([$userId,$reason,$entryId,$tripId,$caseId]);
        $this->event($tripId,$caseId,$userId,'entry_voided',['entry_id'=>$entryId]);$this->reconcileCaseState($tripId,$caseId,$userId);
    }

    public function addEvidence(int $userId,int $tripId,int $caseId,array $input): int
    {
        $this->requireReady();$this->requireOwner($userId,$tripId);$this->caseRow($tripId,$caseId);
        $type=strtolower(trim((string)($input['evidence_type']??'note')));if(!in_array($type,self::EVIDENCE_TYPES,true))$type='other';
        $label=$this->clip((string)($input['label']??''),220);if($label==='')throw new InvalidArgumentException('Evidence label is required.');
        $url=$this->safeReferenceUrl((string)($input['source_url']??''));$bookingId=$this->ownedBookingOrNull($userId,$tripId,(int)($input['source_booking_id']??0));$note=$this->nullableClip((string)($input['note']??''),1200);
        $this->pdo->prepare('INSERT INTO trip_resolution_evidence (dream_trip_id,resolution_case_id,evidence_type,label,source_url,source_booking_id,note,created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$tripId,$caseId,$type,$label,$url,$bookingId,$note,$userId]);
        $id=(int)$this->pdo->lastInsertId();$this->event($tripId,$caseId,$userId,'evidence_added',['evidence_id'=>$id,'evidence_type'=>$type]);return $id;
    }

    /** Owner-only aggregate used by Trip Memory; no private notes/evidence are returned. */
    public function tripFinancialSummary(int $userId,int $tripId): array
    {
        if(!$this->ready())return [];$this->requireOwner($userId,$tripId);$cases=$this->caseRows($tripId);foreach($cases as &$case){$case['entries']=$this->entries((int)$case['id']);$case['financials']=$this->aggregateEntries($case['entries']);}unset($case);return $this->aggregateTrip($cases);
    }

    /** Safe saved context only. Never refreshes providers or reads evidence/private notes. */
    public function safeAgentContext(int $userId,int $limitTrips=3): array
    {
        if(!$this->ready())return [];$limitTrips=max(1,min(5,$limitTrips));$ids=[];
        $q=$this->pdo->prepare("SELECT DISTINCT dt.id FROM dream_trips dt JOIN trip_resolution_cases rc ON rc.dream_trip_id=dt.id WHERE dt.user_id=? AND dt.status<>'abandoned' ORDER BY COALESCE(dt.end_date,'9999-12-31') DESC,dt.id DESC LIMIT {$limitTrips}");$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        if(count($ids)<$limitTrips&&db_table_exists('trip_collaborators')){$remain=$limitTrips-count($ids);$s=$this->pdo->prepare("SELECT DISTINCT tc.dream_trip_id FROM trip_collaborators tc JOIN trip_resolution_cases rc ON rc.dream_trip_id=tc.dream_trip_id WHERE tc.user_id=? AND tc.status='active' ORDER BY tc.dream_trip_id DESC LIMIT {$remain}");$s->execute([$userId]);foreach($s->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$ids[]=(int)$id;}
        $out=[];foreach(array_values(array_unique($ids)) as $tripId){try{$access=$this->access($userId,$tripId);}catch(Throwable){continue;}$trip=$this->trip($tripId);$cases=$this->caseRows($tripId);if(!$cases)continue;$item=['trip_id'=>$tripId,'trip_name'=>(string)$trip['name'],'role'=>(string)($access['role']??'viewer'),'open_cases'=>count(array_filter($cases,static fn(array $c):bool=>!in_array((string)$c['status'],['resolved','closed_no_recovery'],true))),'statuses'=>array_values(array_unique(array_map(static fn(array $c):string=>(string)$c['status'],$cases)))];
            if(!empty($access['is_owner'])){$financial=$this->tripFinancialSummary($userId,$tripId);$item['by_currency']=$financial['by_currency']??[];$item['attention']=$this->attentionSummary($cases);}else{$item['by_currency']=[];$item['attention']=[];}
            $out[]=$item;}
        return $out;
    }

    /** Existing travel-watch worker: deadlines/status only; no provider/mailbox refresh or claim action. */
    public function runUpcoming(int $limit=25): array
    {
        if(!$this->ready())return ['checked'=>0,'cases'=>0,'attention'=>0,'errors'=>0,'upgrade_required'=>true];$limit=max(1,min(100,$limit));
        $sql="SELECT DISTINCT dt.id,dt.user_id FROM dream_trips dt JOIN trip_recovery_incidents ri ON ri.dream_trip_id=dt.id LEFT JOIN trip_resolution_cases rc ON rc.recovery_incident_id=ri.id WHERE dt.status<>'abandoned' AND (rc.id IS NULL OR rc.status NOT IN ('resolved','closed_no_recovery') OR rc.updated_at>=DATE_SUB(NOW(),INTERVAL 90 DAY)) ORDER BY COALESCE(dt.resolution_checked_at,'1970-01-01 00:00:00'),dt.id LIMIT {$limit}";$rows=$this->pdo->query($sql)->fetchAll()?:[];$out=['checked'=>0,'cases'=>0,'attention'=>0,'errors'=>0];
        foreach($rows as $row){$out['checked']++;$tripId=(int)$row['id'];$owner=(int)$row['user_id'];try{$this->syncCases($owner,$tripId);$cases=$this->caseRows($tripId);$this->syncInbox($owner,$tripId,$cases);$this->pdo->prepare('UPDATE dream_trips SET resolution_checked_at=NOW() WHERE id=? AND user_id=?')->execute([$tripId,$owner]);$out['cases']+=count($cases);foreach($cases as $case)if($this->caseNeedsAttention($case))$out['attention']++;}catch(Throwable $e){$out['errors']++;error_log('Disruption Resolution sync failed: '.$e->getMessage());}}
        return $out;
    }

    private function syncCases(int $ownerId,int $tripId): void
    {
        $this->requireOwner($ownerId,$tripId);$q=$this->pdo->prepare("SELECT id,title,source_type,source_key,status FROM trip_recovery_incidents WHERE dream_trip_id=? AND status<>'dismissed' ORDER BY id");$q->execute([$tripId]);$insert=$this->pdo->prepare("INSERT IGNORE INTO trip_resolution_cases (dream_trip_id,recovery_incident_id,status,provider_name,created_by,updated_by) VALUES (?,?,'tracking',?,?,?)");
        foreach($q->fetchAll()?:[] as $incident){$provider=$this->providerForIncident($tripId,$incident);$insert->execute([$tripId,(int)$incident['id'],$provider,$ownerId,$ownerId]);if($insert->rowCount()>0){$caseId=(int)$this->pdo->lastInsertId();$this->event($tripId,$caseId,$ownerId,'case_created',['incident_id'=>(int)$incident['id']]);}}
    }

    private function providerForIncident(int $tripId,array $incident): ?string
    {
        if((string)($incident['source_type']??'')!=='booking'||!ctype_digit((string)($incident['source_key']??'')))return null;$q=$this->pdo->prepare('SELECT provider_name FROM trip_bookings WHERE id=? AND dream_trip_id=? LIMIT 1');$q->execute([(int)$incident['source_key'],$tripId]);$v=trim((string)($q->fetchColumn()?:''));return $v!==''?$this->clip($v,180):null;
    }

    private function reconcileCaseState(int $tripId,int $caseId,int $userId): void
    {
        $case=$this->caseRow($tripId,$caseId);if(in_array((string)$case['status'],['resolved','closed_no_recovery'],true))return;$financial=$this->aggregateEntries($this->entries($caseId));$received=false;$expected=false;foreach($financial['by_currency'] as $row){if(($row['cash_received']??0)>0||($row['credits_received']??0)>0)$received=true;if(($row['cash_expected']??0)>0||($row['credits_expected']??0)>0)$expected=true;}
        $next=(string)$case['status'];if($received&&$expected)$next='partially_recovered';elseif($received&&!$expected)$next='partially_recovered';elseif($expected&&$next==='tracking')$next='claim_needed';if($next!==(string)$case['status']){$this->pdo->prepare('UPDATE trip_resolution_cases SET status=?,updated_by=?,updated_at=NOW() WHERE id=? AND dream_trip_id=?')->execute([$next,$userId,$caseId,$tripId]);$this->event($tripId,$caseId,$userId,'status_changed',['from'=>$case['status'],'to'=>$next,'source'=>'financial_ledger']);}
    }

    private function aggregateTrip(array $cases): array
    {
        $by=[];foreach($cases as $case){$financial=is_array($case['financials']??null)?$case['financials']:$this->aggregateEntries($this->entries((int)$case['id']));foreach((array)($financial['by_currency']??[]) as $currency=>$row){if(!isset($by[$currency]))$by[$currency]=$this->emptyMoneyRow();foreach(array_keys($by[$currency]) as $key)$by[$currency][$key]+=round((float)($row[$key]??0),2);}}
        foreach($by as &$row){$row['cash_outstanding']=round(max(0,$row['cash_expected']-$row['cash_received']),2);$row['credit_outstanding']=round(max(0,$row['credits_expected']-$row['credits_received']),2);$row['net_cash_impact']=round($row['costs']-$row['cash_received'],2);}unset($row);
        return ['by_currency'=>$by,'case_count'=>count($cases),'open_case_count'=>count(array_filter($cases,static fn(array $c):bool=>!in_array((string)$c['status'],['resolved','closed_no_recovery'],true)))];
    }

    private function aggregateEntries(array $entries): array
    {
        $by=[];foreach($entries as $entry){if(!empty($entry['voided_at']))continue;$currency=$this->currency((string)$entry['currency']);if(!isset($by[$currency]))$by[$currency]=$this->emptyMoneyRow();$amount=(float)$entry['amount'];switch((string)$entry['entry_type']){case 'replacement_cost':case 'extra_expense':$by[$currency]['costs']+=$amount;break;case 'refund_expected':case 'insurance_expected':$by[$currency]['cash_expected']+=$amount;break;case 'refund_received':case 'insurance_received':case 'other_recovery':$by[$currency]['cash_received']+=$amount;break;case 'credit_expected':$by[$currency]['credits_expected']+=$amount;break;case 'credit_received':$by[$currency]['credits_received']+=$amount;break;case 'writeoff':$by[$currency]['written_off']+=$amount;break;}}
        foreach($by as &$row){foreach($row as $k=>$v)$row[$k]=round($v,2);$row['cash_outstanding']=round(max(0,$row['cash_expected']-$row['cash_received']),2);$row['credit_outstanding']=round(max(0,$row['credits_expected']-$row['credits_received']),2);$row['net_cash_impact']=round($row['costs']-$row['cash_received'],2);}unset($row);return ['by_currency'=>$by];
    }

    private function emptyMoneyRow(): array{return ['costs'=>0.0,'cash_expected'=>0.0,'cash_received'=>0.0,'credits_expected'=>0.0,'credits_received'=>0.0,'written_off'=>0.0,'cash_outstanding'=>0.0,'credit_outstanding'=>0.0,'net_cash_impact'=>0.0];}

    private function caseRows(int $tripId): array
    {
        $q=$this->pdo->prepare("SELECT rc.*,ri.incident_type,ri.severity,ri.title incident_title,ri.summary incident_summary,ri.status incident_status FROM trip_resolution_cases rc JOIN trip_recovery_incidents ri ON ri.id=rc.recovery_incident_id WHERE rc.dream_trip_id=? ORDER BY FIELD(rc.status,'claim_needed','submitted','awaiting_provider','partially_recovered','tracking','resolved','closed_no_recovery'),COALESCE(rc.claim_deadline,'9999-12-31'),rc.id");$q->execute([$tripId]);return $q->fetchAll()?:[];
    }

    private function caseRow(int $tripId,int $caseId): array
    {
        $q=$this->pdo->prepare('SELECT * FROM trip_resolution_cases WHERE id=? AND dream_trip_id=? LIMIT 1');$q->execute([$caseId,$tripId]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Resolution case not found.');return $row;
    }

    private function entries(int $caseId): array{$q=$this->pdo->prepare('SELECT id,entry_type,amount,currency,provider_name,source_booking_id,occurred_at,note,created_at,voided_at,void_reason FROM trip_resolution_entries WHERE resolution_case_id=? ORDER BY created_at DESC,id DESC LIMIT 200');$q->execute([$caseId]);return $q->fetchAll()?:[];}
    private function evidence(int $caseId): array{$q=$this->pdo->prepare('SELECT id,evidence_type,label,source_url,source_booking_id,note,created_at FROM trip_resolution_evidence WHERE resolution_case_id=? ORDER BY created_at DESC,id DESC LIMIT 100');$q->execute([$caseId]);return $q->fetchAll()?:[];}

    private function caseAttention(array $case): array
    {
        $deadline=!empty($case['claim_deadline'])?strtotime((string)$case['claim_deadline']):false;$follow=!empty($case['next_followup_at'])?strtotime((string)$case['next_followup_at']):false;$closed=in_array((string)$case['status'],['resolved','closed_no_recovery'],true);$now=time();
        return ['deadline_overdue'=>!$closed&&$deadline!==false&&$deadline<$now,'deadline_soon'=>!$closed&&$deadline!==false&&$deadline>=$now&&$deadline<=$now+7*86400,'followup_due'=>!$closed&&$follow!==false&&$follow<=$now];
    }
    private function caseNeedsAttention(array $case): bool{$a=$this->caseAttention($case);return !empty($a['deadline_overdue'])||!empty($a['deadline_soon'])||!empty($a['followup_due'])||(string)$case['status']==='claim_needed';}
    private function attentionSummary(array $cases): array{$out=['deadline_overdue'=>0,'deadline_soon'=>0,'followup_due'=>0,'claim_needed'=>0];foreach($cases as $case){$a=$this->caseAttention($case);foreach(['deadline_overdue','deadline_soon','followup_due'] as $k)if(!empty($a[$k]))$out[$k]++;if((string)$case['status']==='claim_needed')$out['claim_needed']++;}return $out;}

    private function syncInbox(int $ownerId,int $tripId,array $cases): void
    {
        if(!db_table_exists('trip_inbox_items'))return;$active=[];foreach($cases as $case){if(in_array((string)$case['status'],['resolved','closed_no_recovery'],true))continue;$a=$this->caseAttention($case);$kind=null;$title='';$body='';$priority=0;$due=null;
            if(!empty($a['deadline_overdue'])){$kind='deadline';$title='Recovery claim deadline is overdue';$body='A disruption-resolution claim deadline has passed. Review the saved case before assuming any refund or credit is still available.';$priority=100;$due=$case['claim_deadline'];}
            elseif(!empty($a['deadline_soon'])){$kind='deadline';$title='Recovery claim deadline is approaching';$body='A disruption-resolution claim deadline is within seven days. Review the case and provider requirements.';$priority=96;$due=$case['claim_deadline'];}
            elseif(!empty($a['followup_due'])){$kind='followup';$title='Recovery follow-up is due';$body='A saved disruption-resolution case is due for follow-up. Vacation Brain will not contact the provider automatically.';$priority=90;$due=$case['next_followup_at'];}
            elseif((string)$case['status']==='claim_needed'){$kind='claim';$title='A disruption case needs a claim decision';$body='A saved recovery case is marked Claim Needed. Review the evidence and decide whether to contact the provider or insurer.';$priority=88;}
            if($kind===null)continue;$key='case:'.(int)$case['id'].':'.$kind;$active[]=$key;$fingerprint=hash('sha256',$key.'|'.(string)$case['status'].'|'.(string)$due);$sql="INSERT INTO trip_inbox_items (user_id,dream_trip_id,source_type,source_key,thread_key,item_type,priority,urgency,title,body,action_label,action_url,source_status,requires_action,source_fingerprint,due_at,occurred_at) VALUES (?,?,?,?,?,'reminder',?,'now',?,?,?,?,'open',1,?,?,NOW()) ON DUPLICATE KEY UPDATE priority=VALUES(priority),urgency='now',title=VALUES(title),body=VALUES(body),action_label=VALUES(action_label),action_url=VALUES(action_url),source_status='open',requires_action=1,due_at=VALUES(due_at),read_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,read_at),resolved_at=IF(source_fingerprint<>VALUES(source_fingerprint),NULL,resolved_at),source_fingerprint=VALUES(source_fingerprint),updated_at=NOW()";$this->pdo->prepare($sql)->execute([$ownerId,$tripId,'resolution_intelligence',$key,'resolution:'.$tripId,$priority,$title,$body,'Open Resolution Center',app_url('trip-resolution.php?id='.$tripId),$fingerprint,$due]);}
        $params=[$ownerId,$tripId,'resolution_intelligence'];$sql="UPDATE trip_inbox_items SET resolved_at=COALESCE(resolved_at,NOW()),source_status='resolved',updated_at=NOW() WHERE user_id=? AND dream_trip_id=? AND source_type=? AND resolved_at IS NULL";if($active){$sql.=' AND source_key NOT IN ('.implode(',',array_fill(0,count($active),'?')).')';$params=array_merge($params,$active);}$this->pdo->prepare($sql)->execute($params);
    }

    private function event(int $tripId,?int $caseId,?int $userId,string $type,array $payload): void
    {
        if(!db_table_exists('trip_resolution_events'))return;$json=$payload?json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE):null;$this->pdo->prepare('INSERT INTO trip_resolution_events (dream_trip_id,resolution_case_id,user_id,event_type,event_json) VALUES (?,?,?,?,?)')->execute([$tripId,$caseId,$userId,$type,$json]);
    }

    private function access(int $userId,int $tripId): array
    {
        if(class_exists('TripItineraryIntelligenceService'))return (new TripItineraryIntelligenceService($this->pdo))->access($userId,$tripId);$trip=$this->trip($tripId);if((int)$trip['user_id']===$userId)return ['role'=>'owner','is_owner'=>true,'can_view'=>true];throw new OutOfBoundsException('Trip not found or permission denied.');
    }
    private function requireOwner(int $userId,int $tripId): void{$access=$this->access($userId,$tripId);if(empty($access['is_owner']))throw new DomainException('Only the trip owner can manage refund, credit, expense and evidence records.');}
    private function trip(int $tripId): array{$q=$this->pdo->prepare('SELECT * FROM dream_trips WHERE id=? LIMIT 1');$q->execute([$tripId]);$row=$q->fetch();if(!$row)throw new OutOfBoundsException('Trip not found.');return $row;}
    private function publicTrip(array $trip): array{return ['id'=>(int)$trip['id'],'name'=>(string)$trip['name'],'destination_name'=>(string)($trip['destination_name']??''),'currency'=>(string)($trip['currency']??'USD'),'start_date'=>$trip['start_date']??null,'end_date'=>$trip['end_date']??null,'operational_state'=>(string)($trip['operational_state']??'planning')];}
    private function emptySnapshot(array $trip,array $access): array{return ['ready'=>false,'trip'=>$this->publicTrip($trip),'role'=>$access['role']??'viewer','can_manage'=>!empty($access['is_owner']),'can_view_financials'=>!empty($access['is_owner']),'cases'=>[],'summary'=>['total'=>0,'open'=>0,'claim_needed'=>0,'awaiting'=>0,'overdue'=>0],'financials'=>[],'privacy_note'=>'Run System Upgrade for v1.50.','safety_note'=>'No provider action is performed by Resolution Intelligence.'];}
    private function ownedBookingOrNull(int $userId,int $tripId,int $bookingId): ?int{if($bookingId<1)return null;$q=$this->pdo->prepare('SELECT id FROM trip_bookings WHERE id=? AND user_id=? AND dream_trip_id=? LIMIT 1');$q->execute([$bookingId,$userId,$tripId]);return $q->fetchColumn()?(int)$bookingId:null;}
    private function safeReferenceUrl(string $url): ?string{$url=trim($url);if($url==='')return null;if(strlen($url)>1500)throw new InvalidArgumentException('Evidence URL is too long.');if(!preg_match('#^https?://#i',$url))throw new InvalidArgumentException('Evidence URL must begin with http:// or https://.');$host=(string)(parse_url($url,PHP_URL_HOST)?:'');if($host==='')throw new InvalidArgumentException('Evidence URL is invalid.');return $url;}
    private function money(mixed $value): float{if($value===null||trim((string)$value)===''||!is_numeric($value))throw new InvalidArgumentException('Enter a valid amount.');$n=round((float)$value,2);if($n<0||$n>99999999)throw new InvalidArgumentException('Amount is outside the supported range.');return $n;}
    private function currency(string $value): string{$v=strtoupper(trim($value));return preg_match('/^[A-Z]{3}$/',$v)?$v:'USD';}
    private function dateTimeOrNull(string $value): ?string{$v=trim($value);if($v==='')return null;$ts=strtotime($v);if(!$ts)throw new InvalidArgumentException('Enter a valid date/time.');return date('Y-m-d H:i:s',$ts);}
    private function clip(string $value,int $max): string{$v=trim(preg_replace('/\s+/u',' ',$value)??$value);return function_exists('mb_substr')?mb_substr($v,0,$max):substr($v,0,$max);}
    private function nullableClip(string $value,int $max): ?string{$v=$this->clip($value,$max);return $v===''?null:$v;}
    private function requireReady(): void{if(!$this->ready())throw new RuntimeException('Run System Upgrade for Disruption Resolution + Refund/Credit Intelligence.');}
}
