<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();$tripId=(int)($_GET['id']??$_POST['trip_id']??0);if($tripId<1){http_response_code(400);exit('Trip is required.');}
$service=new TripRecoveryIntelligenceService(db());$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$service->ready())throw new RuntimeException('Run System Upgrade for Recovery & Rebooking Intelligence.');
        $action=(string)($_POST['action']??'');
        if($action==='refresh'){$service->refresh($userId,$tripId);flash('success','Recovery research refreshed. No provider reservation was changed.');}
        elseif($action==='status'){$service->setIncidentStatus($userId,$tripId,(int)($_POST['incident_id']??0),(string)($_POST['status']??''));flash('success','Recovery incident updated.');}
        elseif($action==='prepare'){$result=$service->prepareOption($userId,$tripId,(int)($_POST['option_id']??0));$intentId=(int)($result['intent']['id']??0);flash('success','Recovery booking handoff prepared. Nothing has been booked yet.');if($intentId>0)redirect('booking-action.php?id='.$tripId.'&intent='.$intentId);}
        else throw new InvalidArgumentException('Unknown recovery action.');
        redirect('trip-recovery.php?id='.$tripId);
    }catch(InvalidArgumentException|DomainException|OutOfBoundsException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Recovery Center update failed: '.$e->getMessage());$error='Vacation Brain could not update recovery state safely. '.$e->getMessage();}
}
try{$snapshot=$service->snapshot($userId,$tripId,false,true);}catch(OutOfBoundsException $e){http_response_code(404);exit('Trip not found.');}
$trip=$snapshot['trip'];$summary=$snapshot['summary'];$success=flash('success');$pageStyles=['assets/recovery-intelligence.css'];$title='Recovery Center — '.$trip['name'];
function vb_recovery_label(string $value): string{return ucwords(str_replace('_',' ',$value));}
function vb_recovery_money(mixed $amount,?string $currency): string{if($amount===null||$amount==='')return '';return (($currency?:'USD')==='USD'?'$':($currency?:'USD').' ').number_format((float)$amount,2);}
function vb_recovery_time(mixed $value): string{$v=trim((string)$value);if($v==='')return ''; $ts=strtotime($v);return $ts?date('M j · g:i A',$ts):$v;}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-recovery-page"><div class="shell">
  <header class="vb-recovery-head">
    <div><a class="back-link" href="<?=e(app_url('dream-trip.php?id='.$tripId.'&workspace=next'))?>">← Trip workspace</a><span class="eyebrow">Recovery & Rebooking Intelligence</span><h1>Recover the trip without giving the agent a blank check.</h1><p><?=e((string)$trip['name'])?> · Vacation Brain can detect disruption, research alternatives and prepare an approval-ready handoff. Booking, cancellation, payment and provider changes still require the existing explicit transaction flow.</p></div>
    <div class="vb-recovery-actions"><a class="button secondary" href="<?=e(app_url('travel-mode.php?id='.$tripId))?>">Travel Mode</a><a class="button secondary" href="<?=e(app_url('trip-inbox.php?trip_id='.$tripId))?>">Trip Inbox</a><?php if($snapshot['can_plan']):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="refresh"><button class="button primary" type="submit">Refresh live recovery options</button></form><?php endif;?></div>
  </header>

  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$snapshot['ready']):?><div class="alert error"><strong>Recovery & Rebooking Intelligence needs migration 057 / app v1.49.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php endif;?>

  <section class="vb-recovery-summary">
    <article class="<?=($summary['critical']+$summary['high'])>0?'needs-attention':''?>"><span>Open incidents</span><strong><?=(int)$summary['open']?></strong><small><?=(int)$summary['critical']?> critical · <?=(int)$summary['high']?> high</small></article>
    <article><span>Recovery options</span><strong><?=(int)$summary['options']?></strong><small><?=(int)$summary['provider_options']?> from live provider research</small></article>
    <article><span>Your role</span><strong><?=e(vb_recovery_label((string)$snapshot['role']))?></strong><small><?=$snapshot['can_prepare']?'Can prepare provider handoffs':($snapshot['can_plan']?'Can research and plan':'Read-only recovery view')?></small></article>
    <article><span>Transaction boundary</span><strong>Approval required</strong><small>No automatic rebooking or cancellation</small></article>
  </section>

  <div class="vb-recovery-safety"><strong>Recovery research is not a reservation.</strong> Live availability and prices can change. Indicative airfare is planning data. Vacation Brain will not automatically rebook, cancel, purchase, refund, pay, or open checkout.</div>

  <div class="vb-recovery-grid">
    <main>
      <?php $active=array_values(array_filter($snapshot['incidents'],fn($i)=>in_array($i['status'],['open','reviewed'],true)));?>
      <?php if(!$active):?><section class="dashboard-card vb-recovery-empty"><span class="eyebrow">Clear for now</span><h2>No active recovery incident is saved.</h2><p>Vacation Brain will continue checking canonical booking, itinerary and Travel Day Copilot state through the existing travel-watch worker. Use the live refresh when you want fresh weather, airfare or lodging research.</p></section><?php endif;?>
      <?php foreach($active as $incident):?>
        <section class="dashboard-card vb-recovery-incident severity-<?=e((string)$incident['severity'])?>">
          <div class="vb-recovery-incident-head"><div><span class="eyebrow"><?=e(vb_recovery_label((string)$incident['incident_type']))?></span><h2><?=e((string)$incident['title'])?></h2><p><?=e((string)$incident['summary'])?></p></div><div class="vb-recovery-severity"><strong><?=e(strtoupper((string)$incident['severity']))?></strong><small><?=e(vb_recovery_label((string)$incident['status']))?></small></div></div>
          <?php if(!empty($incident['starts_at'])):?><div class="vb-recovery-when">Timing: <?=e(vb_recovery_time($incident['starts_at']))?></div><?php endif;?>
          <div class="vb-recovery-options">
            <?php foreach((array)$incident['options'] as $option):if($option['status']==='dismissed')continue;?>
              <article class="vb-recovery-option status-<?=e((string)$option['status'])?>">
                <div><span><?=e(vb_recovery_label((string)$option['option_type']))?><?php if($option['provider_name']):?> · <?=e((string)$option['provider_name'])?><?php endif;?></span><strong><?=e((string)$option['title'])?></strong><p><?=e((string)$option['summary'])?></p><div class="vb-recovery-meta"><?php if($option['amount']!==null):?><b><?=e(vb_recovery_money($option['amount'],$option['currency']))?></b><?php endif;?><?php if($option['provider_observed_at']):?><small>Provider snapshot <?=e(vb_recovery_time($option['provider_observed_at']))?></small><?php endif;?><?php if($option['expires_at']):?><small>Research expires <?=e(vb_recovery_time($option['expires_at']))?></small><?php endif;?></div></div>
                <div class="vb-recovery-option-actions">
                  <?php if($option['prepared_booking_id']):?><a class="button secondary small" href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Booking candidate #<?=(int)$option['prepared_booking_id']?></a>
                  <?php elseif($option['transaction_required']&&$snapshot['can_prepare']&&$option['status']==='active'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="prepare"><input type="hidden" name="option_id" value="<?=(int)$option['id']?>"><button class="button primary small" type="submit">Prepare booking handoff</button></form>
                  <?php elseif($option['transaction_required']&&!$snapshot['can_prepare']):?><span class="vb-recovery-owner-only">Trip owner approval required</span><?php endif;?>
                  <?php if(!empty($option['source_url'])):?><a class="button secondary small" href="<?=e((string)$option['source_url'])?>" <?=preg_match('#^https://#i',(string)$option['source_url'])?'target="_blank" rel="noopener noreferrer"':''?>>Open option →</a><?php endif;?>
                </div>
              </article>
            <?php endforeach;?>
          </div>
          <?php if($snapshot['can_plan']):?><form method="post" class="vb-recovery-incident-actions"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="action" value="status"><input type="hidden" name="incident_id" value="<?=(int)$incident['id']?>"><button class="button secondary small" name="status" value="reviewed" type="submit">Mark reviewed</button><button class="button secondary small" name="status" value="resolved" type="submit">Resolve</button><button class="button secondary small" name="status" value="dismissed" type="submit">Dismiss</button></form><?php endif;?>
        </section>
      <?php endforeach;?>
    </main>
    <aside>
      <section class="dashboard-card vb-recovery-side"><span class="eyebrow">Recovery sequence</span><h2>How Vacation Brain handles disruption</h2><ol><li>Detect from saved booking, itinerary and traveler state.</li><li>Research public-safe provider alternatives when you explicitly refresh.</li><li>Compare planning options without changing a reservation.</li><li>Owner prepares a provider handoff.</li><li>Booking & Action Execution requires transaction approval.</li><li>Provider checkout or a supported direct mutation remains separately verified.</li></ol></section>
      <section class="dashboard-card vb-recovery-side"><span class="eyebrow">Privacy boundary</span><h2>What recovery never needs</h2><p>No card number, payment method, confirmation code, mailbox token, provider credential, private booking note, or exact current-device location is copied into Recovery Intelligence or ordinary agent context.</p></section>
      <section class="dashboard-card vb-recovery-side"><span class="eyebrow">Useful tools</span><div class="vb-recovery-links"><a href="<?=e(app_url('trip-itinerary.php?id='.$tripId))?>">Complete Itinerary Intelligence →</a><a href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Booking & Readiness →</a><a href="<?=e(app_url('local-concierge.php?id='.$tripId))?>">Local Concierge →</a><a href="<?=e(app_url('trip-inbox.php?trip_id='.$tripId))?>">Trip Inbox →</a></div></section>
    </aside>
  </div>
</div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
