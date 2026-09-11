<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new TripBookingImportService($pdo);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=strtolower(trim((string)($_POST['action']??'')));
        if(!$service->ready())throw new RuntimeException('Run System Upgrade before using Booking Inbox.');
        if($action==='receive'){$row=$service->receive($userId,$_POST,$_FILES['confirmation_file']??null);if(!empty($row['duplicate']))flash('success','That confirmation is already in Booking Inbox.');elseif(!empty($row['booking_id']))flash('success','Confirmation imported and added to the matched trip as Booked. Review it when convenient.');else flash('success','Confirmation imported. Review the trip match or missing details.');redirect('booking-inbox.php?review='.(int)$row['id']);}
        elseif($action==='verify'){$row=$service->verify($userId,(int)($_POST['import_id']??0),$_POST);flash('success','Booking confirmation reviewed and linked to the trip.');redirect('booking-inbox.php?review='.(int)$row['id']);}
        elseif($action==='reject'){$service->reject($userId,(int)($_POST['import_id']??0));flash('success','Booking Inbox item rejected.');redirect('booking-inbox.php');}
        else throw new InvalidArgumentException('Unknown Booking Inbox action.');
    }catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Booking Inbox action failed: '.$e->getMessage());$error='Vacation Brain could not process that confirmation right now.';}
}
$rows=$service->ready()?$service->inbox($userId,120):[];$trips=$service->ready()?$service->trips($userId):[];$reviewId=max(0,(int)($_GET['review']??0));$review=$reviewId&&$service->ready()?$service->get($userId,$reviewId,true):null;$success=flash('success');
$pageStyles=['assets/trip-planning-hub.css','assets/booking-inbox.css'];$title='Booking Inbox — Vacation Brain';
function vb_bi_label(string $v): string{return $v==='verified'?'Traveler verified':ucwords(str_replace('_',' ',$v));}
function vb_bi_dt(mixed $v): string{$s=trim((string)$v);if($s==='')return ''; $t=strtotime($s);return $t?date('M j, Y · g:i A',$t):$s;}
function vb_bi_input_dt(mixed $v): string{$s=trim((string)$v);if($s==='')return ''; $t=strtotime($s);return $t?date('Y-m-d\TH:i',$t):'';}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-booking-inbox"><div class="shell">
  <div class="vb-plan-hub-navrow vb-bi-navrow">
    <nav class="vb-plan-hub-tabs" aria-label="Trip planning sections">
      <a href="<?=e(app_url('dream.php'))?>"><span>Active Trips</span></a>
      <a href="<?=e(app_url('dream.php?view=agents'))?>"><span>Live Agents</span></a>
      <a href="<?=e(app_url('dream.php?view=operations'))?>"><span>Command Center</span></a>
      <a href="<?=e(app_url('dream.php?view=memory'))?>"><span>Memory & Signals</span></a>
      <a class="active" href="<?=e(app_url('booking-inbox.php'))?>" aria-current="page"><span>Booking Inbox</span><?php $need=count(array_filter($rows,fn($r)=>in_array((string)$r['status'],['imported','parsed','needs_review'],true)));if($need>0):?><b><?=$need?></b><?php endif;?></a>
    </nav>
    <a class="button primary vb-plan-hub-add-trip" href="<?=e(app_url('dream-new.php'))?>">+ Add Trip</a>
  </div>

  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$service->ready()):?><div class="trip-intel-alert"><strong>Booking Inbox needs the latest database upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade for migration 052</a><?php else:?>An administrator needs to apply migration 052.<?php endif;?></div><?php endif;?>

  <section class="vb-bi-grid">
    <div class="dashboard-card vb-bi-import">
      <span class="eyebrow">Booking Inbox</span><h1>Import a confirmation</h1>
      <p>Paste a confirmation or upload a supported file. Vacation Brain extracts booking facts, matches the right trip, blocks duplicates, and adds high-confidence matches to the existing booking system.</p>
      <form method="post" enctype="multipart/form-data" class="vb-bi-form">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="receive">
        <label>Trip match<select class="input" name="trip_id"><option value="0">Auto-match to an active trip</option><?php foreach($trips as $trip):?><option value="<?=(int)$trip['id']?>"><?=e((string)$trip['name'])?><?=!empty($trip['destination_name'])?' · '.e((string)$trip['destination_name']):''?></option><?php endforeach;?></select></label>
        <label>Confirmation text<textarea class="input vb-bi-textarea" name="source_text" maxlength="120000" placeholder="Paste the airline, hotel, rental car, event, restaurant, or activity confirmation here..."></textarea></label>
        <label>Or upload confirmation<input class="input" type="file" name="confirmation_file" accept=".txt,.html,.htm,.eml,.pdf,.jpg,.jpeg,.png,.webp,text/plain,text/html,message/rfc822,application/pdf,image/jpeg,image/png,image/webp"><small>TXT, HTML, EML and text-readable PDFs can be parsed locally. Images are accepted as references but are not retained or OCR'd in v1.44; paste their text to finish the import.</small></label>
        <label class="vb-check"><input type="checkbox" name="auto_add" value="1" checked> Automatically add high-confidence matches as <strong>Booked</strong></label>
        <label class="vb-check"><input type="checkbox" name="use_ai" value="1"> Use the configured AI model to improve extraction <small>(explicitly sends the redacted confirmation text to your configured model)</small></label>
        <div class="vb-bi-privacy">Payment-card-like numbers and security codes are removed before the retained source copy is encrypted. Booking confirmation codes stay private and are not put into ordinary agent context. Imported bookings are not provider-verified Confirmed unless a separate provider verification later proves that state.</div>
        <button class="button primary" type="submit">Import confirmation</button>
      </form>
    </div>

    <div class="dashboard-card vb-bi-queue">
      <div class="vb-bi-head"><div><span class="eyebrow">Review queue</span><h2><?=count($rows)?> confirmation<?=count($rows)===1?'':'s'?></h2></div><span class="vb-bi-count <?=$need>0?'attention':''?>"><?=$need?> need review</span></div>
      <div class="vb-bi-list">
      <?php foreach($rows as $row):$p=(array)($row['parsed']??[]);?>
        <a class="vb-bi-row status-<?=e((string)$row['status'])?>" href="<?=e(app_url('booking-inbox.php?review='.(int)$row['id']))?>">
          <span class="vb-bi-type"><?=e(strtoupper(substr((string)($p['booking_type']??'other'),0,1)))?></span>
          <span class="vb-bi-row-copy"><strong><?=e((string)($row['booking_title']??$p['title']??$row['original_filename']??'Booking confirmation'))?></strong><small><?=e(vb_bi_label((string)$row['status']))?><?=!empty($row['trip_name'])?' · '.e((string)$row['trip_name']):''?> · match <?=(int)$row['match_confidence']?>%</small></span>
          <b><?=!empty($row['booking_id'])?'Linked':'Review'?> →</b>
        </a>
      <?php endforeach;?>
      <?php if(!$rows):?><div class="vb-bi-empty"><strong>No confirmations yet.</strong><span>Import one and Vacation Brain will try to place it on the right trip.</span></div><?php endif;?>
      </div>
    </div>
  </section>

  <?php if($review):$p=(array)($review['parsed']??[]);$s=(array)($review['sensitive']??[]);?>
  <section class="dashboard-card vb-bi-review" id="review">
    <div class="vb-bi-head"><div><span class="eyebrow">Private review</span><h2><?=e((string)($p['title']??$review['original_filename']??'Booking confirmation'))?></h2><p><?=e(vb_bi_label((string)$review['status']))?> · <?=e((string)$review['parser_mode'])?> parser · match <?=(int)$review['match_confidence']?>%</p></div><div class="vb-bi-actions"><a class="button secondary small" href="<?=e(app_url('booking-inbox-document.php?id='.(int)$review['id']))?>">Redacted private source copy</a><?php if(!empty($review['booking_id'])&&!empty($review['dream_trip_id'])):?><a class="button secondary small" href="<?=e(app_url('trip-bookings.php?id='.(int)$review['dream_trip_id']))?>">Open linked booking</a><?php endif;?></div></div>
    <?php if(!empty($review['match_reason'])):?><div class="vb-bi-match"><strong>Trip match:</strong> <?=e((string)$review['match_reason'])?></div><?php endif;?>
    <?php if(!empty($review['error_message'])):?><div class="alert error"><?=e((string)$review['error_message'])?></div><?php endif;?>
    <form method="post" class="vb-bi-review-form">
      <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="import_id" value="<?=(int)$review['id']?>">
      <label>Trip<select class="input" name="trip_id" required><option value="">Choose trip</option><?php foreach($trips as $trip):?><option value="<?=(int)$trip['id']?>" <?=(int)($review['dream_trip_id']??0)===(int)$trip['id']?'selected':''?>><?=e((string)$trip['name'])?><?=!empty($trip['destination_name'])?' · '.e((string)$trip['destination_name']):''?></option><?php endforeach;?></select></label>
      <label>Type<select class="input" name="booking_type"><?php foreach(['flight','lodging','transport','event','restaurant','activity','document','other'] as $v):?><option value="<?=$v?>" <?=($p['booking_type']??'other')===$v?'selected':''?>><?=e(vb_bi_label($v))?></option><?php endforeach;?></select></label>
      <label class="span-2">Title<input class="input" name="title" maxlength="180" value="<?=e((string)($p['title']??''))?>"></label>
      <label>Provider<input class="input" name="provider_name" maxlength="180" value="<?=e((string)($p['provider_name']??''))?>"></label>
      <label>Confirmation code<input class="input" name="confirmation_code" maxlength="120" autocomplete="off" value="<?=e((string)($s['confirmation_code']??''))?>"></label>
      <label>Flight number<input class="input" name="flight_number" maxlength="12" value="<?=e((string)($p['flight_number']??''))?>"></label>
      <label>Route<input class="input" name="departure_iata" maxlength="3" placeholder="PHX" value="<?=e((string)($p['departure_iata']??''))?>"><input class="input" name="arrival_iata" maxlength="3" placeholder="SJD" value="<?=e((string)($p['arrival_iata']??''))?>"></label>
      <label>Starts<input class="input" type="datetime-local" name="starts_at" value="<?=e(vb_bi_input_dt($p['starts_at']??''))?>"></label>
      <label>Ends<input class="input" type="datetime-local" name="ends_at" value="<?=e(vb_bi_input_dt($p['ends_at']??''))?>"></label>
      <label>Cancel by<input class="input" type="datetime-local" name="cancellation_deadline" value="<?=e(vb_bi_input_dt($p['cancellation_deadline']??''))?>"></label>
      <label>Check-in opens<input class="input" type="datetime-local" name="checkin_opens_at" value="<?=e(vb_bi_input_dt($p['checkin_opens_at']??''))?>"></label>
      <label>Amount<input class="input" type="number" step="0.01" min="0" name="amount" value="<?=e((string)($p['amount']??''))?>"></label>
      <label>Currency<input class="input" name="currency" maxlength="3" value="<?=e((string)($p['currency']??'USD'))?>"></label>
      <label class="span-2">Provider URL<input class="input" type="url" name="provider_url" maxlength="1500" value="<?=e((string)($p['provider_url']??''))?>"></label>
      <label class="span-2">Notes<textarea class="input" name="notes" maxlength="3000"><?=e((string)($p['notes']??''))?></textarea></label>
      <div class="span-2 vb-bi-review-actions"><button class="button primary" type="submit">Review & link booking</button></div>
    </form>
    <?php if((string)$review['status']!=='rejected'):?><form method="post" class="vb-bi-reject" onsubmit="return confirm('Reject this Booking Inbox item? The linked canonical booking, if any, will not be deleted.');"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="import_id" value="<?=(int)$review['id']?>"><button class="button secondary small" type="submit">Reject Inbox item</button></form><?php endif;?>
  </section>
  <?php endif;?>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
