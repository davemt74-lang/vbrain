<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new AutonomousTravelOperationsService($pdo);$tripId=(int)($_GET['id']??$_POST['trip_id']??0);$error='';
if(!$service->ready()){http_response_code(503);exit('Run System Upgrade to enable Autonomous Travel Operations.');}
try{$trip=(new DreamService($pdo))->get($userId,$tripId,false);if(!$trip)throw new OutOfBoundsException('Trip not found.');}catch(Throwable){http_response_code(404);exit('Trip not found.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{$service->saveSettings($userId,$tripId,$_POST);flash('success','Autonomous Travel Operations policy saved.');redirect('autonomous-trip.php?id='.$tripId);}catch(Throwable $e){$error=$e->getMessage();}
}
$status=$service->status($userId,$tripId);$p=$status['policy'];$usage=$status['usage'];$recent=$status['recent'];$success=flash('success');$title='Autonomous Travel Operations — '.(string)$trip['name'];$pageStyles=['assets/autonomous-travel.css'];require __DIR__.'/partials/header.php';
$modeLabels=['observe'=>'Observe only','research'=>'Research autopilot','planning'=>'Planning autopilot'];
?>
<section class="dashboard vb-autonomy"><div class="shell">
  <header class="vb-autonomy-head"><div><a class="back-link" href="<?=e(app_url('dream-trip.php?id='.$tripId))?>">← Back to trip</a><div class="eyebrow">Autonomous Travel Operations</div><h1>Give Vacation Brain a leash. Not your credit card.</h1><p><?=e((string)$trip['name'])?> · standing policy is trip-specific and off by default.</p></div><a class="button secondary" href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Booking & Trip Readiness</a></header>
  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>

  <section class="vb-autonomy-summary">
    <article class="dashboard-card"><span>Autonomy</span><strong><?=empty($p['enabled'])?'Off':e($modeLabels[(string)$p['mode']]??'Observe only')?></strong><small><?=empty($p['enabled'])?'No automatic actions are authorized.':'Saved standing policy'?></small></article>
    <article class="dashboard-card"><span>Research today</span><strong><?=(int)$usage['started']?> / <?=(int)$p['max_auto_starts_per_day']?></strong><small>Automatic specialist starts</small></article>
    <article class="dashboard-card"><span>Planning today</span><strong><?=(int)$usage['applied']?> / <?=(int)$p['max_auto_applies_per_day']?></strong><small>Non-transactional applies</small></article>
    <article class="dashboard-card"><span>Estimated plan value</span><strong>$<?=number_format((float)$usage['estimated_amount'],2)?></strong><small>Recorded estimates, never charges</small></article>
  </section>

  <?php if(!empty($status['verification_pending'])):?><div class="alert warning"><strong>Provider verification is pending.</strong> Planning autopilot is <?=!empty($p['pause_on_verification_pending'])?'paused by policy':'not configured to pause'?>. Do not retry an uncertain destructive provider action until its state is verified.</div><?php endif;?>

  <div class="vb-autonomy-layout">
    <form method="post" class="dashboard-card vb-autonomy-settings"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>">
      <div class="vb-autonomy-section-head"><div><span class="eyebrow">Standing policy</span><h2>How much can the trip agent do?</h2></div><label class="vb-autonomy-master"><input type="checkbox" name="enabled" value="1" <?=!empty($p['enabled'])?'checked':''?>><span>Enable for this trip</span></label></div>
      <div class="vb-autonomy-modes"><?php foreach($modeLabels as $value=>$label):?><label><input type="radio" name="autonomy_mode" value="<?=$value?>" <?=($p['mode']??'observe')===$value?'checked':''?>><span><strong><?=e($label)?></strong><small><?php if($value==='observe'):?>Record policy state only. No automatic research or planning changes.<?php elseif($value==='research'):?>Vacation Brain may accept/start eligible Next Moves, but every proposed trip change still waits.<?php else:?>May also apply eligible low-risk planning additions that pass every limit below.<?php endif;?></small></span></label><?php endforeach;?></div>

      <div class="vb-autonomy-grid"><label>Minimum Next Move priority<input class="input" type="number" name="minimum_priority" min="0" max="100" step="1" value="<?=(int)$p['minimum_priority']?>"><small>Higher = fewer automatic starts. 90 is conservative.</small></label><label>Automatic research starts / day<input class="input" type="number" name="max_auto_starts_per_day" min="0" max="24" step="1" value="<?=(int)$p['max_auto_starts_per_day']?>"></label><label>Automatic planning applies / day<input class="input" type="number" name="max_auto_applies_per_day" min="0" max="24" step="1" value="<?=(int)$p['max_auto_applies_per_day']?>"></label><label>Estimated value limit / item<input class="input" type="number" name="per_action_estimated_limit" min="0" max="999999.99" step="0.01" value="<?=e(number_format((float)$p['per_action_estimated_limit'],2,'.',''))?>"><small>$0 means only zero/unknown-price planning additions.</small></label><label>Estimated value limit / day<input class="input" type="number" name="daily_estimated_limit" min="0" max="999999.99" step="0.01" value="<?=e(number_format((float)$p['daily_estimated_limit'],2,'.',''))?>"><small>This is an itinerary estimate cap, never spending authority.</small></label></div>

      <fieldset class="vb-autonomy-types"><legend>Planning changes allowed under standing policy</legend><label><input type="checkbox" name="allow_planning_task" value="1" <?=in_array('planning_task',(array)$p['allowed_proposal_types'],true)?'checked':''?>><span><strong>Planning tasks</strong><small>Add a non-transactional planning note/item through the canonical Trip Action path.</small></span></label><label><input type="checkbox" name="allow_itinerary_item" value="1" <?=in_array('itinerary_item',(array)$p['allowed_proposal_types'],true)?'checked':''?>><span><strong>Itinerary additions</strong><small>Add low-risk idea, food, activity, or experience items. Existing itinerary items are not deleted or rewritten.</small></span></label></fieldset>
      <label class="vb-autonomy-toggle"><input type="checkbox" name="pause_on_verification_pending" value="1" <?=!empty($p['pause_on_verification_pending'])?'checked':''?>><span><strong>Pause planning while a provider mutation is verification pending</strong><small>Recommended. Prevents autopilot from reacting to an uncertain cancellation/provider outcome.</small></span></label>
      <label class="vb-autonomy-toggle"><input type="checkbox" name="notifications_enabled" value="1" <?=!empty($p['notifications_enabled'])?'checked':''?>><span><strong>Notify me when planning autopilot applies something</strong><small>Automatic research starts remain visible in the audit ledger without notification spam.</small></span></label>

      <div class="vb-autonomy-boundary"><strong>Hard boundary — not configurable</strong><p>This policy never authorizes provider checkout, reservations, bookings, tickets, purchases, payment, refunds, cancellation, provider handoffs, flight/lodging booking items, merchandise, destructive changes, or retrying an uncertain provider mutation. Those remain behind Booking & Action Execution and explicit transaction approval.</p></div>
      <button class="button primary" type="submit">Save autonomy policy</button>
    </form>

    <aside class="vb-autonomy-side">
      <article class="dashboard-card"><span class="eyebrow">Current queue</span><h2><?=(int)$status['pending_approvals']?> proposal<?=((int)$status['pending_approvals']===1?'':'s')?> waiting</h2><p class="muted">Research mode leaves all proposals here. Planning mode may apply only proposals that pass the standing-policy and hard-safety checks.</p></article>
      <article class="dashboard-card"><span class="eyebrow">Audit ledger</span><h2>Recent autonomous decisions</h2><?php if($recent):?><div class="vb-autonomy-ledger"><?php foreach(array_slice($recent,0,12) as $row):?><div class="decision <?=e((string)$row['decision_type'])?>"><span><?=e(strtoupper((string)$row['decision_type']))?> · <?=e(date('M j · g:i A',strtotime((string)$row['created_at'])))?></span><strong><?=e((string)($row['detail']['proposal_title']??$row['detail']['title']??ucwords(str_replace('_',' ',(string)($row['proposal_type']??'Trip operation')))))?></strong><p><?=e((string)$row['reason'])?></p><?php if($row['estimated_amount']!==null):?><small>Estimated planning value: $<?=number_format((float)$row['estimated_amount'],2)?> · no charge authorized</small><?php endif;?></div><?php endforeach;?></div><?php else:?><p class="muted">No autonomous decisions have been recorded for this trip.</p><?php endif;?></article>
    </aside>
  </div>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
