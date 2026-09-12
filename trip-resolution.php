<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();$pdo=db();$tripId=(int)($_GET['id']??$_POST['trip_id']??0);if($tripId<1){http_response_code(400);exit('Trip is required.');}
$service=new TripDisruptionResolutionService($pdo);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$service->ready())throw new RuntimeException('Run System Upgrade for Disruption Resolution + Refund/Credit Intelligence.');
        $action=(string)($_POST['action']??'');$caseId=(int)($_POST['case_id']??0);
        if($action==='save_case'){$service->saveCase($userId,$tripId,$caseId,$_POST);flash('success','Resolution case updated. No provider or financial action was sent.');}
        elseif($action==='add_entry'){$service->addEntry($userId,$tripId,$caseId,$_POST);flash('success','Resolution ledger entry added.');}
        elseif($action==='void_entry'){$service->voidEntry($userId,$tripId,$caseId,(int)($_POST['entry_id']??0),(string)($_POST['void_reason']??''));flash('success','Resolution ledger entry voided with an audit reason.');}
        elseif($action==='add_evidence'){$service->addEvidence($userId,$tripId,$caseId,$_POST);flash('success','Evidence reference added. No document was sent to a provider or agent.');}
        else throw new InvalidArgumentException('Unknown resolution action.');
        redirect('trip-resolution.php?id='.$tripId.'#case-'.$caseId);
    }catch(InvalidArgumentException|DomainException|OutOfBoundsException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Resolution Center update failed: '.$e->getMessage());$error='Vacation Brain could not update the resolution ledger safely. '.$e->getMessage();}
}
try{$snapshot=$service->snapshot($userId,$tripId,true);}catch(OutOfBoundsException $e){http_response_code(404);exit('Trip not found.');}
$trip=$snapshot['trip'];$success=flash('success');$pageStyles=['assets/disruption-resolution.css'];$title='Resolution Center — '.$trip['name'];
$bookingChoices=[];if($snapshot['can_manage']&&class_exists('TripBookingService')){try{$bs=new TripBookingService($pdo);if($bs->ready())$bookingChoices=$bs->bookings($userId,$tripId);}catch(Throwable){}}
function vb_resolution_label(string $v): string{return ucwords(str_replace('_',' ',$v));}
function vb_resolution_money(mixed $amount,string $currency): string{return ($currency==='USD'?'$':$currency.' ').number_format((float)$amount,2);}
function vb_resolution_dt(mixed $value,bool $input=false): string{$v=trim((string)$value);if($v==='')return '';$ts=strtotime($v);if(!$ts)return $v;return $input?date('Y-m-d\TH:i',$ts):date('M j, Y · g:i A',$ts);}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-resolution-page"><div class="shell">
  <header class="vb-resolution-head">
    <div><a class="back-link" href="<?=e(app_url('trip-recovery.php?id='.$tripId))?>">← Recovery Center</a><span class="eyebrow">Disruption Resolution + Refund/Credit Intelligence</span><h1>Track what the disruption actually cost — and what came back.</h1><p><?=e((string)$trip['name'])?> · Keep recovery deadlines, replacement costs, refunds, credits and evidence together without giving Vacation Brain authority to submit a claim or move money.</p></div>
    <div class="vb-resolution-actions"><a class="button secondary" href="<?=e(app_url('trip-inbox.php?trip_id='.$tripId))?>">Trip Inbox</a><a class="button secondary" href="<?=e(app_url('trip-memory.php?id='.$tripId))?>">Trip Memory</a></div>
  </header>

  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$snapshot['ready']):?><div class="alert error"><strong>Resolution Intelligence needs migration 058 / app v1.50.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php endif;?>

  <section class="vb-resolution-summary">
    <article><span>Resolution cases</span><strong><?=(int)$snapshot['summary']['open']?> open</strong><small><?=(int)$snapshot['summary']['total']?> total</small></article>
    <article class="<?=$snapshot['summary']['claim_needed']?'needs-attention':''?>"><span>Claim decisions</span><strong><?=(int)$snapshot['summary']['claim_needed']?></strong><small>Owner-controlled</small></article>
    <article class="<?=$snapshot['summary']['overdue']?'needs-attention':''?>"><span>Deadlines / follow-ups</span><strong><?=(int)$snapshot['summary']['overdue']?></strong><small>Need attention</small></article>
    <article><span>Your role</span><strong><?=e(vb_resolution_label((string)$snapshot['role']))?></strong><small><?=$snapshot['can_manage']?'Full owner ledger': 'Shared status view only'?></small></article>
  </section>

  <?php if($snapshot['can_view_financials']&&$snapshot['financials']['by_currency']??[]):?>
  <section class="dashboard-card vb-resolution-financials"><div class="vb-resolution-section-head"><div><span class="eyebrow">Reconciliation</span><h2>Disruption cost vs. recovery</h2></div><span>Credits stay separate from cash</span></div><div class="vb-resolution-money-grid">
    <?php foreach($snapshot['financials']['by_currency'] as $currency=>$m):?>
      <article><strong><?=e($currency)?></strong><dl><div><dt>Replacement + extra cost</dt><dd><?=e(vb_resolution_money($m['costs'],$currency))?></dd></div><div><dt>Cash recovered</dt><dd><?=e(vb_resolution_money($m['cash_received'],$currency))?></dd></div><div><dt>Cash still expected</dt><dd><?=e(vb_resolution_money($m['cash_outstanding'],$currency))?></dd></div><div><dt>Credits received</dt><dd><?=e(vb_resolution_money($m['credits_received'],$currency))?></dd></div><div class="net"><dt>Net cash impact</dt><dd><?=e(vb_resolution_money($m['net_cash_impact'],$currency))?></dd></div></dl></article>
    <?php endforeach;?>
  </div></section>
  <?php endif;?>

  <div class="vb-resolution-safety"><strong>Tracking is not execution.</strong> Refunds, credits and reimbursements are user-recorded ledger facts until you verify them. Vacation Brain does not submit claims, contact providers, issue refunds, move money, rebook, cancel or open checkout from this page.</div>

  <div class="vb-resolution-grid">
    <main>
      <?php if(!$snapshot['cases']):?><section class="dashboard-card vb-resolution-empty"><span class="eyebrow">Nothing to reconcile yet</span><h2>No Recovery Intelligence incidents have produced resolution cases.</h2><p>When a disruption is detected, Vacation Brain can create a resolution case around it. The existing travel-watch worker only tracks deadlines and status; it never contacts a provider.</p><a class="button secondary" href="<?=e(app_url('trip-recovery.php?id='.$tripId))?>">Open Recovery Center</a></section><?php endif;?>
      <?php foreach($snapshot['cases'] as $case):$attention=$case['attention']??[];?>
      <section class="dashboard-card vb-resolution-case" id="case-<?=(int)$case['id']?>">
        <div class="vb-resolution-case-head"><div><span class="eyebrow"><?=e(vb_resolution_label((string)$case['incident_type']))?> · <?=e(strtoupper((string)$case['severity']))?></span><h2><?=e((string)$case['incident_title'])?></h2><p><?=e((string)$case['incident_summary'])?></p></div><div class="vb-resolution-state status-<?=e((string)$case['status'])?>"><strong><?=e(vb_resolution_label((string)$case['status']))?></strong><small>Resolution state</small></div></div>
        <?php if(!empty($attention['deadline_overdue'])||!empty($attention['deadline_soon'])||!empty($attention['followup_due'])):?><div class="vb-resolution-attention"><?php if(!empty($attention['deadline_overdue'])):?><strong>Claim deadline overdue.</strong><?php elseif(!empty($attention['deadline_soon'])):?><strong>Claim deadline within seven days.</strong><?php endif;?><?php if(!empty($attention['followup_due'])):?><span>Follow-up is due.</span><?php endif;?></div><?php endif;?>

        <?php if($snapshot['can_manage']):?>
        <form method="post" class="vb-resolution-case-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="case_id" value="<?=(int)$case['id']?>"><input type="hidden" name="action" value="save_case">
          <label>Status<select name="status"><?php foreach(['tracking','claim_needed','submitted','awaiting_provider','partially_recovered','resolved','closed_no_recovery'] as $status):?><option value="<?=$status?>" <?=$case['status']===$status?'selected':''?>><?=e(vb_resolution_label($status))?></option><?php endforeach;?></select></label>
          <label>Provider / insurer<input name="provider_name" maxlength="180" value="<?=e((string)($case['provider_name']??''))?>"></label>
          <label>Claim deadline<input type="datetime-local" name="claim_deadline" value="<?=e(vb_resolution_dt($case['claim_deadline']??'',true))?>"></label>
          <label>Next follow-up<input type="datetime-local" name="next_followup_at" value="<?=e(vb_resolution_dt($case['next_followup_at']??'',true))?>"></label>
          <label class="wide">Private resolution note<textarea name="resolution_summary" rows="3" maxlength="1200"><?=e((string)($case['resolution_summary']??''))?></textarea><small>Excluded from ordinary agent and collaborator context.</small></label>
          <button class="button secondary small" type="submit">Save resolution state</button>
        </form>

        <div class="vb-resolution-ledger">
          <div class="vb-resolution-section-head"><div><span class="eyebrow">Financial ledger</span><h3>Costs, expected recovery and received recovery</h3></div><span>Append-only · corrections are voided</span></div>
          <?php foreach((array)($case['financials']['by_currency']??[]) as $currency=>$m):?><div class="vb-resolution-case-money"><b><?=e($currency)?></b><span>Costs <?=e(vb_resolution_money($m['costs'],$currency))?></span><span>Cash recovered <?=e(vb_resolution_money($m['cash_received'],$currency))?></span><span>Outstanding <?=e(vb_resolution_money($m['cash_outstanding'],$currency))?></span><span>Credits <?=e(vb_resolution_money($m['credits_received'],$currency))?></span></div><?php endforeach;?>
          <form method="post" class="vb-resolution-entry-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="case_id" value="<?=(int)$case['id']?>"><input type="hidden" name="action" value="add_entry">
            <label>Entry type<select name="entry_type" required><?php foreach(['replacement_cost','extra_expense','refund_expected','refund_received','credit_expected','credit_received','insurance_expected','insurance_received','other_recovery','writeoff'] as $type):?><option value="<?=$type?>"><?=e(vb_resolution_label($type))?></option><?php endforeach;?></select></label>
            <label>Amount<input name="amount" inputmode="decimal" required placeholder="0.00"></label><label>Currency<input name="currency" maxlength="3" value="<?=e((string)($trip['currency']??'USD'))?>"></label>
            <label>Provider<input name="provider_name" maxlength="180"></label><label>When<input type="datetime-local" name="occurred_at"></label>
            <label>Related booking<select name="source_booking_id"><option value="">None</option><?php foreach($bookingChoices as $booking):?><option value="<?=(int)$booking['id']?>"><?=e((string)$booking['title'])?></option><?php endforeach;?></select></label>
            <label class="wide">Private ledger note<input name="note" maxlength="1200" placeholder="Optional context; excluded from ordinary agent context"></label><button class="button primary small" type="submit">Add ledger entry</button>
          </form>
          <div class="vb-resolution-entry-list"><?php foreach((array)$case['entries'] as $entry):?><article class="<?=!empty($entry['voided_at'])?'voided':''?>"><div><strong><?=e(vb_resolution_label((string)$entry['entry_type']))?> · <?=e(vb_resolution_money($entry['amount'],(string)$entry['currency']))?></strong><small><?=e((string)($entry['provider_name']??''))?><?=!empty($entry['occurred_at'])?' · '.e(vb_resolution_dt($entry['occurred_at'])):''?></small><?php if(!empty($entry['note'])):?><p><?=e((string)$entry['note'])?></p><?php endif;?><?php if(!empty($entry['voided_at'])):?><em>Voided: <?=e((string)$entry['void_reason'])?></em><?php endif;?></div><?php if(empty($entry['voided_at'])):?><form method="post" class="vb-resolution-void"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="case_id" value="<?=(int)$case['id']?>"><input type="hidden" name="entry_id" value="<?=(int)$entry['id']?>"><input type="hidden" name="action" value="void_entry"><input name="void_reason" maxlength="500" required placeholder="Correction reason"><button class="button secondary small" type="submit">Void</button></form><?php endif;?></article><?php endforeach;?></div>
        </div>

        <div class="vb-resolution-evidence"><div class="vb-resolution-section-head"><div><span class="eyebrow">Evidence</span><h3>Keep the support trail together</h3></div><span>Metadata only · no automatic claim submission</span></div>
          <form method="post" class="vb-resolution-evidence-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="case_id" value="<?=(int)$case['id']?>"><input type="hidden" name="action" value="add_evidence">
            <label>Type<select name="evidence_type"><?php foreach(['booking','receipt','provider_message','policy','itinerary','note','other'] as $type):?><option value="<?=$type?>"><?=e(vb_resolution_label($type))?></option><?php endforeach;?></select></label><label>Label<input name="label" maxlength="220" required></label><label class="wide">Reference URL<input name="source_url" maxlength="1500" placeholder="https://…"></label><label>Related booking<select name="source_booking_id"><option value="">None</option><?php foreach($bookingChoices as $booking):?><option value="<?=(int)$booking['id']?>"><?=e((string)$booking['title'])?></option><?php endforeach;?></select></label><label class="wide">Private evidence note<input name="note" maxlength="1200"></label><button class="button secondary small" type="submit">Add evidence reference</button>
          </form>
          <div class="vb-resolution-evidence-list"><?php foreach((array)$case['evidence'] as $evidence):?><article><span><?=e(vb_resolution_label((string)$evidence['evidence_type']))?></span><strong><?=e((string)$evidence['label'])?></strong><?php if(!empty($evidence['note'])):?><p><?=e((string)$evidence['note'])?></p><?php endif;?><?php if(!empty($evidence['source_url'])):?><a href="<?=e((string)$evidence['source_url'])?>" target="_blank" rel="noopener noreferrer">Open reference ↗</a><?php endif;?></article><?php endforeach;?></div>
        </div>
        <?php else:?><div class="vb-resolution-shared"><strong>Shared resolution status</strong><p>Financial entries, evidence and private resolution notes are visible only to the trip owner. Your shared view shows whether the incident is still being tracked, awaiting a provider, partially recovered or resolved.</p><?php if(!empty($case['claim_deadline'])):?><span>Saved claim deadline: <?=e(vb_resolution_dt($case['claim_deadline']))?></span><?php endif;?></div><?php endif;?>
      </section>
      <?php endforeach;?>
    </main>
    <aside><section class="dashboard-card vb-resolution-side"><span class="eyebrow">What counts as recovery</span><h2>Cash and credits are different.</h2><p>Vacation Brain keeps refunds and insurance reimbursements in cash recovery, while provider credits remain a separate value. It does not pretend a future credit is cash back in your account.</p></section><section class="dashboard-card vb-resolution-side"><span class="eyebrow">Evidence boundary</span><h2>References, not a claim robot.</h2><p>Store a label, optional URL, related booking and private note. Vacation Brain does not upload that evidence to a provider, insurer or language model from this workflow.</p></section><section class="dashboard-card vb-resolution-side"><span class="eyebrow">Next systems</span><div class="vb-resolution-links"><a href="<?=e(app_url('trip-recovery.php?id='.$tripId))?>">Recovery & Rebooking →</a><a href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Booking & Readiness →</a><a href="<?=e(app_url('trip-memory.php?id='.$tripId))?>">Trip Memory →</a><a href="<?=e(app_url('trip-inbox.php?trip_id='.$tripId))?>">Trip Inbox →</a></div></section></aside>
  </div>
</div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
