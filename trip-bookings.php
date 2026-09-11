<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();$pdo=db();$tripId=(int)($_GET['id']??$_POST['trip_id']??0);$bookings=new TripBookingService($pdo);$dreams=new DreamService($pdo);$error='';
$trip=$dreams->get($userId,$tripId,false);if(!$trip){http_response_code(404);exit('Trip not found.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=strtolower(trim((string)($_POST['action']??'')));
        if(!$bookings->ready())throw new RuntimeException('Run System Upgrade before using Booking + Trip Readiness.');
        if($action==='save_booking'){$bookings->saveBooking($userId,$tripId,$_POST);flash('success','Booking saved.');}
        elseif($action==='set_booking_status'){$bookings->updateBookingStatus($userId,$tripId,(int)($_POST['booking_id']??0),(string)($_POST['status']??''));flash('success','Booking status updated.');}
        elseif($action==='save_requirement'){$bookings->saveRequirement($userId,$tripId,$_POST);flash('success','Readiness requirement updated.');}
        else throw new InvalidArgumentException('Unknown booking action.');
        redirect('trip-bookings.php?id='.$tripId);
    }catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Trip booking update failed: '.$e->getMessage());$error='Vacation Brain could not update booking readiness right now.';}
}

$snapshot=$bookings->snapshot($userId,$tripId);$readiness=$snapshot['readiness'];$bookingRows=$snapshot['bookings'];$requirements=$snapshot['requirements'];$success=flash('success');
$pageStyles=['assets/trip-bookings.css'];$title='Booking & Readiness — '.$trip['name'];

function vb_booking_money(mixed $value,string $currency): string{if($value===null||$value==='')return '—';return ($currency==='USD'?'$':$currency.' ').number_format((float)$value,2);}
function vb_booking_dt(mixed $value): string{$v=trim((string)$value);if($v==='')return ''; $ts=strtotime($v);return $ts?date('M j, Y · g:i A',$ts):$v;}
function vb_booking_input_dt(mixed $value): string{$v=trim((string)$value);if($v==='')return ''; $ts=strtotime($v);return $ts?date('Y-m-d\TH:i',$ts):'';}
function vb_booking_label(string $value): string{return ucwords(str_replace('_',' ',$value));}

require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-booking-page">
<div class="shell">
  <div class="vb-booking-head">
    <div><a class="back-link" href="<?=e(app_url('dream-trip.php?id='.$tripId.'&tab=overview'))?>">← Trip dashboard</a><span class="eyebrow">Trip operations</span><h1>Booking & Trip Readiness</h1><p><?=e($trip['name'])?> · <?=e((string)($trip['destination_name']??''))?> · <?=e((string)($trip['date_label']??''))?></p></div>
    <div class="vb-booking-head-actions"><a class="button secondary small" href="<?=e(app_url('watches.php'))?>">Watches</a><a class="button primary small" href="#add-booking">+ Add booking</a></div>
  </div>

  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$snapshot['ready']):?><div class="trip-intel-alert"><strong>Booking + Trip Readiness needs the latest database upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?>An administrator needs to apply migration 042.<?php endif;?></div><?php endif;?>

  <section class="vb-readiness-hero">
    <div class="vb-readiness-score"><div class="vb-readiness-ring" style="--vb-ready:<?=max(0,min(100,(int)$readiness['percent']))?>"><span><strong><?=(int)$readiness['percent']?>%</strong><small>ready</small></span></div><div><span class="eyebrow">Trip readiness</span><h2><?=e((string)$readiness['status_label'])?></h2><p>Readiness measures the reservations and requirements you actually need for this trip — not how much planning content exists.</p></div></div>
    <div class="vb-readiness-stats">
      <div><strong><?=(int)$readiness['secured']?></strong><span>Required items secured</span></div>
      <div><strong><?=(int)$readiness['outstanding']?></strong><span>Required items open</span></div>
      <div><strong><?=(int)$readiness['ready_to_book']?></strong><span>Ready to book</span></div>
      <div><strong><?=(int)$readiness['deadlines_soon']?></strong><span>Deadlines · 7 days</span></div>
      <div><strong><?=(int)$readiness['checkins_soon']?></strong><span>Check-ins · 72h</span></div>
      <div><strong><?=(int)$readiness['payment_due']?></strong><span>Payments unresolved</span></div>
    </div>
  </section>

  <section class="dashboard-card vb-booking-section">
    <div class="vb-booking-section-head"><div><span class="eyebrow">Readiness model</span><h2>What this trip actually requires</h2><p>Mark only what this trip needs. Optional categories stay neutral until you make them required.</p></div></div>
    <div class="vb-requirement-grid">
      <?php foreach($requirements as $req):?>
      <form class="vb-requirement-card <?=$req['status']==='complete'||$req['status']==='not_needed'?'is-complete':''?>" method="post">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_requirement"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="requirement_id" value="<?=(int)$req['id']?>"><input type="hidden" name="requirement_type" value="<?=e((string)$req['requirement_type'])?>"><input type="hidden" name="title" value="<?=e((string)$req['title'])?>"><input type="hidden" name="sort_order" value="<?=(int)$req['sort_order']?>">
        <div class="vb-requirement-title"><span><?=e(strtoupper(substr((string)$req['requirement_type'],0,1)))?></span><div><strong><?=e((string)$req['title'])?></strong><small><?=e(vb_booking_label((string)$req['requirement_type']))?></small></div></div>
        <label class="vb-check"><input type="checkbox" name="is_required" value="1" <?=(int)$req['is_required']===1?'checked':''?>> Required for this trip</label>
        <label>Status<select class="input" name="status"><?php foreach(['open'=>'Open','complete'=>'Complete','not_needed'=>'Not needed'] as $v=>$l):?><option value="<?=$v?>" <?=$req['status']===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></label>
        <label>Due / reminder<input class="input" type="datetime-local" name="due_at" value="<?=e(vb_booking_input_dt($req['due_at']??''))?>"></label>
        <label>Notes<input class="input" name="notes" maxlength="700" value="<?=e((string)($req['notes']??''))?>" placeholder="Anything Vacation Brain should remember"></label>
        <button class="button secondary small" type="submit">Save requirement</button>
      </form>
      <?php endforeach;?>
    </div>
  </section>

  <section class="dashboard-card vb-booking-section">
    <div class="vb-booking-section-head"><div><span class="eyebrow">Reservations & confirmations</span><h2>What has actually been secured</h2><p>Vacation Brain never treats an agent suggestion as a completed reservation. External bookings stay pending until you confirm them here.</p></div><span class="vb-booking-safe">Live confirmation required</span></div>
    <div class="vb-booking-list">
      <?php foreach($bookingRows as $booking):?>
      <article class="vb-booking-card status-<?=e((string)$booking['status'])?>">
        <div class="vb-booking-card-top"><div class="vb-booking-type"><?=e(vb_booking_label((string)$booking['booking_type']))?></div><span class="vb-booking-status"><?=e(vb_booking_label((string)$booking['status']))?></span></div>
        <div class="vb-booking-card-main"><div><h3><?=e((string)$booking['title'])?></h3><p><?=e((string)($booking['provider_name']??''))?><?=!empty($booking['confirmation_code'])?' · Confirmation '.e((string)$booking['confirmation_code']):''?></p></div><strong><?=e(vb_booking_money($booking['amount'],$booking['currency']))?></strong></div>
        <div class="vb-booking-meta">
          <?php if(!empty($booking['starts_at'])):?><span><b>Starts</b><?=e(vb_booking_dt($booking['starts_at']))?></span><?php endif;?>
          <?php if(!empty($booking['cancellation_deadline'])):?><span><b>Cancel by</b><?=e(vb_booking_dt($booking['cancellation_deadline']))?></span><?php endif;?>
          <?php if(!empty($booking['checkin_opens_at'])):?><span><b>Check-in opens</b><?=e(vb_booking_dt($booking['checkin_opens_at']))?></span><?php endif;?>
          <span><b>Payment</b><?=e(vb_booking_label((string)$booking['payment_status']))?></span>
          <span><b>Source</b><?=e(vb_booking_label((string)$booking['source']))?></span>
        </div>
        <?php if(!empty($booking['requires_live_confirmation'])):?><div class="vb-live-confirmation">Confirm live availability, final price, cancellation terms, and payment with the provider before relying on this booking.</div><?php endif;?>
        <?php if(!empty($booking['notes'])):?><p class="vb-booking-notes"><?=e((string)$booking['notes'])?></p><?php endif;?>
        <div class="vb-booking-actions">
          <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="set_booking_status"><input type="hidden" name="booking_id" value="<?=(int)$booking['id']?>"><select class="input small" name="status"><?php foreach(['unbooked','ready_to_book','booked','confirmed','changed','cancelled'] as $status):?><option value="<?=$status?>" <?=$booking['status']===$status?'selected':''?>><?=e(vb_booking_label($status))?></option><?php endforeach;?></select><button class="button secondary small" type="submit">Update</button></form>
          <?php if(!empty($booking['provider_url'])):?><a class="button secondary small" href="<?=e((string)$booking['provider_url'])?>" target="_blank" rel="noopener">Open provider ↗</a><?php endif;?>
          <details><summary>Edit details</summary><form class="vb-booking-edit" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="save_booking"><input type="hidden" name="booking_id" value="<?=(int)$booking['id']?>"><?php require __DIR__.'/partials/trip-booking-fields.php';?><button class="button primary small" type="submit">Save booking</button></form></details>
        </div>
      </article>
      <?php endforeach;?>
      <?php if(!$bookingRows):?><div class="vb-booking-empty"><strong>No reservations recorded yet.</strong><span>Add a booking manually or approve an agent booking handoff. Vacation Brain will track what remains outstanding.</span></div><?php endif;?>
    </div>
  </section>

  <section class="dashboard-card vb-booking-section" id="add-booking">
    <div class="vb-booking-section-head"><div><span class="eyebrow">Manual confirmation</span><h2>Add a booking or reservation</h2><p>Use this for anything you booked outside Vacation Brain.</p></div></div>
    <?php $booking=['booking_type'=>'lodging','title'=>'','provider_name'=>'','confirmation_code'=>'','status'=>'booked','payment_status'=>'unknown','amount'=>null,'currency'=>$trip['currency']??'USD','starts_at'=>'','ends_at'=>'','cancellation_deadline'=>'','checkin_opens_at'=>'','provider_url'=>'','notes'=>'']; ?>
    <form class="vb-booking-form" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="save_booking"><?php require __DIR__.'/partials/trip-booking-fields.php';?><button class="button primary" type="submit">Save booking</button></form>
  </section>
</div>
</section>
<?php require __DIR__.'/partials/footer.php';?>
