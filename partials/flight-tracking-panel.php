<?php
if(!isset($userId,$tripId))return;
$flightTracking=new TripFlightTrackingService(db());$flightTrackingRows=$flightTracking->ready()?$flightTracking->rows((int)$userId,(int)$tripId):[];
?>
<section class="dashboard vb-flight-tracking-wrap" id="live-flight-tracking"><div class="shell"><div class="dashboard-card vb-flight-tracking-panel">
  <div class="vb-flight-tracking-head"><div><span class="eyebrow">Live flight status</span><h2>Track the flights you actually booked.</h2><p>Save the public flight number and route. Vacation Brain can then refresh operational status, terminal, gate, delay and estimated times through the configured flight-status provider.</p></div><span class="vb-flight-privacy">Confirmation codes and booking notes are never sent to the flight-status provider.</span></div>
  <?php if(!$flightTracking->ready()):?><div class="alert warning"><strong>Live flight tracking needs migration 046 / app v1.38.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php elseif(!$flightTrackingRows):?><div class="vb-flight-empty">Add a flight reservation above first. Once a flight booking exists, its live tracking details can be connected here.</div><?php else:?>
  <div class="vb-flight-tracking-grid">
    <?php foreach($flightTrackingRows as $flight):?>
    <form method="post" action="<?=e(app_url('flight-tracking.php'))?>" class="vb-flight-tracking-card">
      <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=(int)$tripId?>"><input type="hidden" name="booking_id" value="<?=(int)$flight['id']?>">
      <div class="vb-flight-tracking-title"><div><strong><?=e((string)$flight['title'])?></strong><span><?=e((string)($flight['provider_name']??''))?><?=!empty($flight['starts_at'])?' · '.e(date('M j, Y',strtotime((string)$flight['starts_at']))):''?></span></div><b><?=e(ucwords(str_replace('_',' ',(string)$flight['status'])))?></b></div>
      <div class="vb-flight-tracking-fields"><label>Flight number<input class="input" name="flight_number" maxlength="12" value="<?=e((string)($flight['flight_number']??''))?>" placeholder="AA123"></label><label>Depart<input class="input" name="departure_iata" maxlength="3" value="<?=e((string)($flight['departure_iata']??''))?>" placeholder="PHX"></label><label>Arrive<input class="input" name="arrival_iata" maxlength="3" value="<?=e((string)($flight['arrival_iata']??''))?>" placeholder="SJD"></label></div>
      <button class="button secondary small" type="submit">Save live tracking</button>
    </form>
    <?php endforeach;?>
  </div><?php endif;?>
</div></div></section>
