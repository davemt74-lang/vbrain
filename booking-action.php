<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();$pdo=db();$service=new TripBookingActionService($pdo);$bookings=new TripBookingService($pdo);$dreams=new DreamService($pdo);
$tripId=(int)($_GET['id']??$_POST['trip_id']??0);$intentId=(int)($_GET['intent']??$_POST['intent_id']??0);$bookingId=(int)($_GET['booking']??$_POST['booking_id']??0);$mode=strtolower(trim((string)($_GET['mode']??'')));$error='';
$trip=$dreams->get($userId,$tripId,false);if(!$trip){http_response_code(404);exit('Trip not found.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$service->ready())throw new RuntimeException('Run System Upgrade to enable Booking & Action Execution.');
        $command=strtolower(trim((string)($_POST['command']??'')));
        if($command==='ensure_handoff'){
            $intent=$service->ensureHandoffIntent($userId,$tripId,$bookingId,(int)($_POST['action_id']??0)?:null,(int)($_POST['execution_id']??0)?:null);
            $intent=$service->prepareHandoff($userId,$tripId,(int)$intent['id']);
            redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='prepare_handoff'){
            $intent=$service->prepareHandoff($userId,$tripId,$intentId);flash('success','Provider checkout details refreshed. Review them before approval.');redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='prepare_cancel'){
            $intent=$service->prepareBookingComCancellation($userId,$tripId,$bookingId,$_POST);flash('success','Booking.com cancellation terms verified. Nothing has been cancelled yet.');redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='approve'){
            $intent=$service->approve($userId,$tripId,$intentId);flash('success','Provider action approved. No provider mutation occurred during approval.');redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='execute'){
            $intent=$service->execute($userId,$tripId,$intentId);flash('success','Provider action completed and verified.');redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='open_handoff'){
            $url=$service->openHandoff($userId,$tripId,$intentId);header('Location: '.$url,true,303);exit;
        }elseif($command==='confirm_handoff'){
            $intent=$service->confirmHandoff($userId,$tripId,$intentId,(string)($_POST['outcome']??''));
            flash(($intent['status']??'')==='completed'?'success':'booking_action_error',($intent['status']??'')==='completed'?'Provider checkout recorded as completed. The booking is marked Booked, not provider-verified Confirmed.':'Provider checkout recorded as not completed. You can prepare a fresh handoff when ready.');
            redirect('booking-action.php?id='.$tripId.'&intent='.(int)$intent['id']);
        }elseif($command==='cancel_intent'){
            $service->cancelIntent($userId,$tripId,$intentId);flash('success','Provider action cancelled. No provider mutation was sent.');redirect('trip-bookings.php?id='.$tripId);
        }else throw new InvalidArgumentException('Unknown booking action command.');
    }catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Booking action update failed: '.$e->getMessage());$error='Vacation Brain could not complete this provider action safely. '.$e->getMessage();}
}

$intent=$intentId>0?$service->intent($userId,$tripId,$intentId):null;$booking=null;
if($intent&&$intent['booking_id'])$bookingId=(int)$intent['booking_id'];
if($bookingId>0&&$bookings->ready()){foreach($bookings->bookings($userId,$tripId) as $row){if((int)$row['id']===$bookingId){$booking=$row;break;}}}
$receipts=$intent?$service->receipts($userId,$tripId,(int)$intent['id']):[];$success=flash('success');$flashError=flash('booking_action_error');if(!$error&&$flashError)$error=$flashError;
$pageStyles=['assets/booking-actions.css'];$title='Booking Action — '.$trip['name'];
function vb_ba_label(string $value): string{return ucwords(str_replace('_',' ',$value));}
function vb_ba_money(mixed $value,string $currency): string{if($value===null||$value==='')return 'Not quoted';return ($currency==='USD'?'$':$currency.' ').number_format((float)$value,2);}
function vb_ba_dt(mixed $value): string{$v=trim((string)$value);if($v==='')return '—';$ts=strtotime($v);return $ts?date('M j, Y · g:i A',$ts):$v;}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-ba-page"><div class="shell">
  <div class="vb-ba-head"><div><a class="back-link" href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">← Booking & Readiness</a><span class="eyebrow">Controlled provider action</span><h1>Review before anything happens</h1><p><?=e($trip['name'])?> · Vacation Brain separates planning approval from transaction approval.</p></div><div class="vb-ba-shield">Approval required</div></div>
  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$service->ready()):?><div class="trip-intel-alert"><strong>Booking & Action Execution needs migration 048.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php endif;?>

  <?php if(!$intent):?>
    <?php if(!$booking):?><section class="dashboard-card vb-ba-empty"><h2>No booking action selected</h2><p>Return to Booking & Readiness and choose a booking or cancellation action.</p></section>
    <?php elseif($mode==='cancel'):?>
      <section class="dashboard-card vb-ba-card"><span class="eyebrow">Direct provider action</span><h2>Prepare Booking.com cancellation</h2><p><strong><?=e((string)$booking['title'])?></strong></p><div class="vb-ba-warning">Nothing is cancelled by this form. Vacation Brain first retrieves the current Booking.com status, cancellation deadline, and fee, then asks for a separate approval.</div>
        <form method="post" class="vb-ba-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="booking_id" value="<?=$bookingId?>"><input type="hidden" name="command" value="prepare_cancel">
          <label>Booking.com order reference <input class="input" name="order_reference" maxlength="180" autocomplete="off" placeholder="Optional if you have the reservation reference"></label>
          <label>Booking.com reservation reference <input class="input" name="reservation_reference" maxlength="180" autocomplete="off" placeholder="Optional if you have the order reference"></label>
          <label>Cancellation reason <input class="input" name="reason" maxlength="240" value="Change in travel plans"></label>
          <div class="vb-ba-privacy"><strong>Private by design:</strong> operational references are encrypted at rest and are excluded from agent prompts, quote JSON, notifications, and receipts.</div>
          <button class="button primary" type="submit">Check current cancellation terms</button>
        </form>
      </section>
    <?php else:?>
      <section class="dashboard-card vb-ba-card"><span class="eyebrow">Provider-hosted checkout</span><h2><?=e((string)$booking['title'])?></h2><p>Vacation Brain will lock the current handoff facts, show exactly what is known, and require another approval before opening the provider.</p>
        <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="booking_id" value="<?=$bookingId?>"><input type="hidden" name="command" value="ensure_handoff"><button class="button primary" type="submit">Prepare provider checkout</button></form>
      </section>
    <?php endif;?>
  <?php else:$quote=$intent['quote'];?>
    <section class="vb-ba-hero status-<?=e((string)$intent['status'])?>">
      <div><span class="eyebrow"><?=e(vb_ba_label((string)$intent['adapter_mode']))?></span><h2><?=e(vb_ba_label((string)$intent['action_type']))?> · <?=e(vb_ba_label((string)$intent['provider_slug']))?></h2><p><?=e($booking?(string)$booking['title']:'Provider action')?></p></div><div class="vb-ba-state"><strong><?=e(vb_ba_label((string)$intent['status']))?></strong><small>Transaction state</small></div>
    </section>

    <?php if(in_array($intent['status'],['awaiting_approval','approved','executing','handoff_pending','verification_pending','completed','failed'],true)):?>
    <section class="dashboard-card vb-ba-card"><div class="vb-ba-card-head"><div><span class="eyebrow">Locked quote</span><h2>Current → Proposed</h2></div><span class="vb-ba-source"><?=e(vb_ba_label((string)($quote['source_state']?:'unknown')))?> source</span></div>
      <div class="vb-ba-compare"><div><small>Current booking</small><strong><?=e($booking?vb_ba_label((string)$booking['status']):'Recorded trip item')?></strong><span><?=e($booking?vb_ba_money($booking['amount'],$booking['currency']):'—')?></span></div><div class="vb-ba-arrow">→</div><div><small>Proposed action</small><strong><?=e(vb_ba_label((string)$intent['action_type']))?></strong><span><?=e(vb_ba_money($quote['amount'],(string)($quote['currency']?:'USD')))?></span></div></div>
      <?php if($quote['fee']!==null):?><div class="vb-ba-fee"><strong>Cancellation fee</strong><span><?=e(vb_ba_money($quote['fee'],(string)($quote['currency']?:'USD')))?></span></div><?php endif;?>
      <div class="vb-ba-terms"><strong>Terms Vacation Brain is asking you to approve</strong><p><?=e((string)$quote['terms'])?></p></div>
      <div class="vb-ba-quote-meta"><span>Quote expires: <?=e(vb_ba_dt($quote['expires_at']))?></span><span>Digest: <?=e(substr((string)$quote['digest'],0,12))?>…</span><?php if($quote['expired']):?><strong>Expired — refresh before approval</strong><?php endif;?></div>
    </section>
    <?php endif;?>

    <section class="dashboard-card vb-ba-card"><span class="eyebrow">Approval & execution</span><h2>What Vacation Brain can do now</h2>
      <?php if($intent['status']==='awaiting_approval'):?><div class="vb-ba-warning">This is the transaction approval. For direct cancellation, approval authorizes one provider request after an immediate terms recheck. For a handoff, approval only authorizes opening the provider checkout page.</div><div class="vb-ba-actions"><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="approve"><button class="button primary" type="submit" <?=$quote['expired']?'disabled':''?>>Approve this provider action</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="cancel_intent"><button class="button secondary" type="submit">Cancel action</button></form></div>
      <?php elseif($intent['status']==='approved'&&$intent['adapter_mode']==='provider_handoff'):?><p>Approved. Vacation Brain has not opened or completed checkout yet.</p><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="open_handoff"><button class="button primary" type="submit">Open provider checkout ↗</button></form>
      <?php elseif($intent['status']==='approved'&&$intent['adapter_mode']==='booking_com_cancel'):?><div class="vb-ba-danger"><strong>Final action:</strong> this will re-check the Booking.com cancellation terms. If they are unchanged, Vacation Brain will submit one cancellation request. If they changed, execution stops and asks you to approve the new quote.</div><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="execute"><button class="button primary" type="submit">Execute approved cancellation</button></form>
      <?php elseif($intent['status']==='handoff_pending'):?><div class="vb-ba-warning">Vacation Brain cannot see what happened on the provider site. Tell it whether checkout actually completed.</div><div class="vb-ba-actions"><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="confirm_handoff"><input type="hidden" name="outcome" value="completed"><button class="button primary" type="submit">I completed the booking</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="intent_id" value="<?=(int)$intent['id']?>"><input type="hidden" name="command" value="confirm_handoff"><input type="hidden" name="outcome" value="not_completed"><button class="button secondary" type="submit">I did not complete it</button></form></div>
      <?php elseif($intent['status']==='verification_pending'):?><div class="vb-ba-danger"><strong>Do not retry.</strong> The provider request outcome is uncertain. Vacation Brain intentionally stopped instead of repeating a destructive action. Verify the reservation with the provider before taking another action.</div>
      <?php elseif($intent['status']==='completed'):?><div class="vb-ba-complete"><strong>Action completed.</strong><span>Vacation Brain recorded the execution receipt and synchronized the trip booking state.</span></div>
      <?php elseif($intent['status']==='failed'):?><div class="vb-ba-danger"><strong>Action stopped safely.</strong><span><?=e((string)($intent['error']?:'The provider action did not complete.'))?></span></div>
      <?php else:?><p>Prepare or refresh the provider quote before approval.</p><?php endif;?>
    </section>

    <section class="dashboard-card vb-ba-card"><div class="vb-ba-card-head"><div><span class="eyebrow">Audit trail</span><h2>Execution receipts</h2></div><span><?=count($receipts)?> events</span></div><div class="vb-ba-timeline">
      <?php foreach($receipts as $receipt):?><div class="vb-ba-event"><span></span><div><strong><?=e(vb_ba_label((string)$receipt['event_type']))?></strong><small><?=e(vb_ba_dt($receipt['created_at']))?> · <?=e(vb_ba_label((string)$receipt['provider_slug']))?></small><?php if($receipt['receipt']):?><p><?=e(implode(' · ',array_map(fn($k,$v)=>vb_ba_label((string)$k).': '.(is_bool($v)?($v?'yes':'no'):(is_scalar($v)?(string)$v:'recorded')),array_keys($receipt['receipt']),array_values($receipt['receipt']))))?></p><?php endif;?></div></div><?php endforeach;?>
      <?php if(!$receipts):?><p>No execution receipts yet.</p><?php endif;?>
    </div></section>
  <?php endif;?>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
