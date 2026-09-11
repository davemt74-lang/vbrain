<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new DreamService($pdo);$trips=$service->all($userId);$collaboration=new TripCollaborationService($pdo);$shared=$collaboration->ready()?$collaboration->sharedWithMe($userId):[];$pageStyles=['assets/trip-intelligence.css','assets/trip-collaboration.css'];$title='Plan Trips — Vacation Brain';require __DIR__.'/partials/header.php';
$roleLabels=['co_planner'=>'Co-planner','traveler'=>'Traveler','viewer'=>'Viewer'];
?>
<section class="dashboard trip-index-page"><div class="shell">
<div class="dashboard-head trip-index-head"><div><div class="eyebrow">Trip Intelligence · Plan Trip</div><h1>Trips the brain is currently thinking about.</h1><p class="muted">Each trip gets its own weather, flight, event, local-business, itinerary and budget intelligence workspace.</p></div><a class="button primary" href="<?=e(app_url('dream-new.php'))?>">+ New trip</a></div>
<div class="trip-index-grid">
<?php foreach($trips as $trip):?>
<a class="trip-index-card" href="<?=e(app_url('dream-trip.php?id='.(int)$trip['id']))?>">
  <div class="trip-index-card-top"><span class="trip-status-pill"><?=e($trip['status_label'])?></span><span class="dream-level"><?=str_repeat('●',(int)$trip['dream_level']).str_repeat('○',5-(int)$trip['dream_level'])?></span></div>
  <h2><?=e($trip['name'])?></h2><p class="trip-destination"><?=e($trip['destination_name']?:'Destination TBD')?></p>
  <div class="trip-index-route"><span><?=e((string)($trip['origin_iata']??$trip['origin_name']??'Origin TBD'))?></span><strong>→</strong><span><?=e((string)($trip['destination_iata']??$trip['destination_name']??'Destination'))?></span></div>
  <div class="trip-index-meta"><span><?=e((string)($trip['date_label']??'Dates flexible'))?></span><span><?=(int)$trip['travelers']?> traveler<?=(int)$trip['travelers']===1?'':'s'?></span><?php if($trip['target_budget']!==null):?><span>$<?=number_format((float)$trip['target_budget'],0)?> budget</span><?php endif;?></div>
  <div class="trip-readiness"><div><strong><?=e($trip['temperature'])?></strong><span><?=(int)$trip['booking_readiness']?>%</span></div><div class="trait-meter"><span style="width:<?=(int)$trip['booking_readiness']?>%"></span></div></div>
  <div class="trip-index-foot"><span><?=(int)$trip['item_count']?> saved plan items · <?=(int)$trip['view_count']?> revisits</span><?php if(!empty($trip['intelligence_refreshed_at'])):?><span>Intel <?=e(date('M j',strtotime((string)$trip['intelligence_refreshed_at'])))?></span><?php else:?><span>Intel waiting</span><?php endif;?></div>
</a>
<?php endforeach;?>
<?php if(!$trips):?><article class="dashboard-card trip-index-empty"><div class="eyebrow">No trips yet</div><h2>Extremely responsible behavior.</h2><p class="muted">Start a trip and Vacation Brain will begin building destination intelligence around it.</p><a class="button primary" href="<?=e(app_url('dream-new.php'))?>">Create your first trip →</a></article><?php endif;?>
</div>

<?php if($shared):?><section class="vb-shared-index"><div class="dashboard-head"><div><div class="eyebrow">Shared with me</div><h2>Trips other brains invited you into.</h2><p class="muted">Shared workspaces expose only role-appropriate itinerary and trip logistics.</p></div></div><div class="trip-index-grid"><?php foreach($shared as $trip):?><a class="trip-index-card vb-shared-index-card" href="<?=e(app_url('shared-trip.php?id='.(int)$trip['id']))?>"><div class="trip-index-card-top"><span class="trip-status-pill"><?=e($roleLabels[(string)$trip['role']]??ucfirst((string)$trip['role']))?></span><span class="vb-rsvp-badge"><?=e(ucwords(str_replace('_',' ',(string)$trip['rsvp'])))?></span></div><h2><?=e((string)$trip['name'])?></h2><p class="trip-destination"><?=e((string)($trip['destination_name']?:'Destination TBD'))?></p><div class="trip-index-meta"><?php if(!empty($trip['start_date'])):?><span><?=e(date('M j, Y',strtotime((string)$trip['start_date'])))?></span><?php else:?><span>Dates flexible</span><?php endif;?><span>Owner: <?=e((string)($trip['owner_name']?:$trip['owner_username']?:'Traveler'))?></span></div><div class="trip-index-foot"><span>Open shared itinerary, RSVP & votes</span><span>Private owner data excluded</span></div></a><?php endforeach;?></div></section><?php endif;?>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>