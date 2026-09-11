<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();
$pdo=db();
$service=new DreamService($pdo);
$trips=$service->all($userId);
$collaboration=new TripCollaborationService($pdo);
$shared=$collaboration->ready()?$collaboration->sharedWithMe($userId):[];
$views=['trips'=>'Active Trips','agents'=>'Live Agents','operations'=>'Command Center','memory'=>'Memory & Signals'];
$activeView=strtolower(trim((string)($_GET['view']??'trips')));if(!isset($views[$activeView]))$activeView='trips';
$activeTrips=array_values(array_filter($trips,static fn(array $trip): bool=>!in_array((string)($trip['status']??''),['completed','abandoned'],true)));
$completedTrips=array_values(array_filter($trips,static fn(array $trip): bool=>(string)($trip['status']??'')==='completed'));
$roleLabels=['co_planner'=>'Co-planner','traveler'=>'Traveler','viewer'=>'Viewer'];
$activity=['score'=>0,'status'=>'Quiet','summary'=>'Vacation Brain is waiting for trip activity.','stats'=>[],'channels'=>[]];
$commandSnapshot=['summary'=>[],'active_agents'=>[],'attention'=>[]];
$activeAgentCount=0;
if($activeView==='agents'){
    try{$activity=(new VacationBrainActivityService($pdo))->snapshot($userId);}catch(Throwable $e){error_log('Planning hub activity failed: '.$e->getMessage());}
    try{$commandSnapshot=(new TripCommandCenterService($pdo))->snapshot($userId);}catch(Throwable $e){error_log('Planning hub command snapshot failed: '.$e->getMessage());}
    $activeAgentCount=(int)($commandSnapshot['summary']['active_agents']??count((array)($commandSnapshot['active_agents']??[])));
}
$pageStyles=['assets/trip-intelligence.css','assets/trip-collaboration.css','assets/brain-activity.css','assets/trip-command-center.css','assets/traveler-memory-summary.css','assets/proactive-travel.css','assets/trip-planning-hub.css'];
$title='Plan Trips — Vacation Brain';

function plan_hub_wave_points(array $series,int $w=620,int $h=70): string{
    if(!$series)$series=[0,0];$count=max(1,count($series)-1);$points=[];
    foreach(array_values($series) as $i=>$raw){$value=max(0,min(100,(int)$raw));$x=($i*$w)/$count;$base=$h*.72;$amplitude=($value/100)*($h*.6);$phase=$i%2===0?-1:1;$y=max(3,min($h-3,$base+($phase*$amplitude)));$points[]=number_format($x,1,'.','').','.number_format($y,1,'.','');}
    return implode(' ',$points);
}
function plan_hub_trip_card(array $trip,TripCollaborationService $collaboration): void{?>
<article class="trip-index-card vb-owner-trip-card">
  <a class="vb-owner-trip-main" href="<?=e(app_url('dream-trip.php?id='.(int)$trip['id']))?>">
    <div class="trip-index-card-top"><span class="trip-status-pill"><?=e((string)$trip['status_label'])?></span><span class="dream-level"><?=str_repeat('●',(int)$trip['dream_level']).str_repeat('○',5-(int)$trip['dream_level'])?></span></div>
    <h2><?=e((string)$trip['name'])?></h2><p class="trip-destination"><?=e((string)($trip['destination_name']?:'Destination TBD'))?></p>
    <div class="trip-index-route"><span><?=e((string)($trip['origin_iata']??$trip['origin_name']??'Origin TBD'))?></span><strong>→</strong><span><?=e((string)($trip['destination_iata']??$trip['destination_name']??'Destination'))?></span></div>
    <div class="trip-index-meta"><span><?=e((string)($trip['date_label']??'Dates flexible'))?></span><span><?=(int)$trip['travelers']?> traveler<?=(int)$trip['travelers']===1?'':'s'?></span><?php if($trip['target_budget']!==null):?><span>$<?=number_format((float)$trip['target_budget'],0)?> budget</span><?php endif;?></div>
    <div class="trip-readiness"><div><strong><?=e((string)$trip['temperature'])?></strong><span><?=(int)$trip['booking_readiness']?>%</span></div><div class="trait-meter"><span style="width:<?=(int)$trip['booking_readiness']?>%"></span></div></div>
    <div class="trip-index-foot"><span><?=(int)$trip['item_count']?> saved plan items · <?=(int)$trip['view_count']?> revisits</span><?php if(!empty($trip['intelligence_refreshed_at'])):?><span>Intel <?=e(date('M j',strtotime((string)$trip['intelligence_refreshed_at'])))?></span><?php else:?><span>Intel waiting</span><?php endif;?></div>
  </a>
  <?php if($collaboration->ready()):?><div class="vb-owner-trip-actions"><a href="<?=e(app_url('shared-trip.php?id='.(int)$trip['id']))?>">Shared workspace</a><a href="<?=e(app_url('trip-collaboration.php?id='.(int)$trip['id']))?>">Travelers & access</a></div><?php endif;?>
</article>
<?php }
require __DIR__.'/partials/header.php';
?>
<section class="dashboard trip-index-page vb-plan-hub"><div class="shell">
  <div class="vb-plan-hub-navrow">
    <nav class="vb-plan-hub-tabs" aria-label="Trip planning sections">
      <?php foreach($views as $key=>$label):$count=$key==='trips'?count($activeTrips):($key==='agents'&&$activeView==='agents'?$activeAgentCount:null);?>
        <a class="<?=$activeView===$key?'active':''?>" href="<?=e(app_url('dream.php?view='.$key))?>" aria-current="<?=$activeView===$key?'page':'false'?>"><span><?=e($label)?></span><?php if($count!==null):?><b><?=$count?></b><?php endif;?></a>
      <?php endforeach;?>
      <a href="<?=e(app_url('booking-inbox.php'))?>"><span>Booking Inbox</span></a>
    </nav>
    <a class="button primary vb-plan-hub-add-trip" href="<?=e(app_url('dream-new.php'))?>">+ Add Trip</a>
  </div>

  <?php if($activeView==='trips'):?>
    <section class="vb-plan-hub-panel" data-plan-view="trips">
      <div class="vb-plan-hub-panel-head"><div><span class="eyebrow">Planning horizon</span><h2>Active trips</h2><p>Open a trip to work with its specialist agents, Next Moves, live data, proactive monitoring and settings.</p></div><span class="vb-plan-hub-count"><?=count($activeTrips)?> active</span></div>
      <div class="trip-index-grid"><?php foreach($activeTrips as $trip)plan_hub_trip_card($trip,$collaboration);?><?php if(!$activeTrips):?><article class="dashboard-card trip-index-empty"><div class="eyebrow">No active trips</div><h2>Extremely responsible behavior.</h2><p class="muted">Start a trip and Vacation Brain will begin building destination intelligence around it.</p><a class="button primary" href="<?=e(app_url('dream-new.php'))?>">Create your first trip →</a></article><?php endif;?></div>

      <?php if($shared):?><section class="vb-shared-index"><div class="dashboard-head"><div><div class="eyebrow">Shared with me</div><h2>Trips other brains invited you into.</h2><p class="muted">Shared workspaces expose only role-appropriate itinerary and trip logistics.</p></div></div><div class="trip-index-grid"><?php foreach($shared as $trip):?><a class="trip-index-card vb-shared-index-card" href="<?=e(app_url('shared-trip.php?id='.(int)$trip['id']))?>"><div class="trip-index-card-top"><span class="trip-status-pill"><?=e($roleLabels[(string)$trip['role']]??ucfirst((string)$trip['role']))?></span><span class="vb-rsvp-badge"><?=e(ucwords(str_replace('_',' ',(string)$trip['rsvp'])))?></span></div><h2><?=e((string)$trip['name'])?></h2><p class="trip-destination"><?=e((string)($trip['destination_name']?:'Destination TBD'))?></p><div class="trip-index-meta"><?php if(!empty($trip['start_date'])):?><span><?=e(date('M j, Y',strtotime((string)$trip['start_date'])))?></span><?php else:?><span>Dates flexible</span><?php endif;?><span>Owner: <?=e((string)($trip['owner_name']?:$trip['owner_username']?:'Traveler'))?></span></div><div class="trip-index-foot"><span>Open shared itinerary, RSVP & votes</span><span>Private owner data excluded</span></div></a><?php endforeach;?></div></section><?php endif;?>

      <?php if($completedTrips):?><details class="vb-plan-hub-completed"><summary>Completed trips <span><?=count($completedTrips)?></span></summary><div class="trip-index-grid"><?php foreach($completedTrips as $trip)plan_hub_trip_card($trip,$collaboration);?></div></details><?php endif;?>
    </section>

  <?php elseif($activeView==='agents'):?>
    <section class="vb-plan-hub-panel" data-plan-view="agents">
      <div class="vb-plan-hub-panel-head"><div><span class="eyebrow">Vacation Brain Activity</span><h2>Live planning agents</h2><p>This is the trip-planning AI activity that used to live on Today. It now stays with your trips.</p></div><a class="button secondary small" href="<?=e(app_url('dream.php?view=agents'))?>">Refresh snapshot</a></div>
      <div class="vb-brain-shell vb-plan-brain" data-plan-brain data-brain-api="<?=e(app_url('api/brain-activity.php'))?>" style="--brain-intensity:<?=max(0,min(100,(int)($activity['score']??0)))?>">
        <div class="vb-brain-main"><div class="vb-brain-top"><div><div class="vb-brain-kicker"><i class="vb-brain-live-dot"></i>Vacation Brain Activity</div><h2 class="vb-brain-title" data-plan-brain-status><?=e((string)($activity['status']??'Quiet'))?></h2><p class="vb-brain-summary" data-plan-brain-summary><?=e((string)($activity['summary']??''))?></p></div><div class="vb-brain-score"><strong data-plan-brain-score><?=max(0,min(100,(int)($activity['score']??0)))?></strong><span>Brain activity</span></div></div>
        <div class="vb-brain-wave-wrap"><span class="vb-brain-wave-label">Last 24 hours · actual system activity</span><svg class="vb-brain-wave" viewBox="0 0 620 70" preserveAspectRatio="none" aria-hidden="true"><polyline class="line" data-plan-brain-wave points="<?=e(plan_hub_wave_points((array)($activity['series']??[])))?>"></polyline></svg></div>
        <?php $stats=(array)($activity['stats']??[]);?><div class="vb-brain-stats"><div class="vb-brain-stat"><strong data-plan-stat="trips_planned"><?=(int)($stats['trips_planned']??0)?></strong><span>Trips planned</span></div><div class="vb-brain-stat"><strong data-plan-stat="active_watches"><?=(int)($stats['active_watches']??0)?></strong><span>Active watches</span></div><div class="vb-brain-stat"><strong data-plan-stat="watch_alerts_24h"><?=(int)($stats['watch_alerts_24h']??0)?></strong><span>Watch alerts · 24h</span></div><div class="vb-brain-stat"><strong data-plan-stat="agent_actions_24h"><?=(int)($stats['agent_actions_24h']??0)?></strong><span>Agent results · 24h</span></div><div class="vb-brain-stat"><strong data-plan-stat="provider_refreshes_24h"><?=(int)($stats['provider_refreshes_24h']??0)?></strong><span>Provider refreshes</span></div><div class="vb-brain-stat"><strong data-plan-stat="itinerary_items"><?=(int)($stats['itinerary_items']??0)?></strong><span>Itinerary items</span></div></div></div>
        <div class="vb-brain-agents" data-plan-brain-channels><?php foreach((array)($activity['channels']??[]) as $channel):?><a class="vb-brain-agent" data-state="<?=e((string)($channel['state']??'idle'))?>" href="<?=e((string)($channel['url']??'#'))?>"><div class="vb-brain-agent-head"><strong><?=e((string)($channel['label']??'Agent'))?></strong><span class="vb-brain-agent-level"><?=(int)($channel['intensity']??0)?>%</span></div><div class="vb-brain-agent-state"><i></i><?=e((string)($channel['state']??'idle'))?></div><svg class="vb-brain-agent-wave" viewBox="0 0 140 32" preserveAspectRatio="none" aria-hidden="true"><polyline points="<?=e(plan_hub_wave_points((array)($channel['series']??[]),140,32))?>"></polyline></svg><span class="vb-brain-agent-reason"><?=e((string)($channel['reason']??''))?></span></a><?php endforeach;?></div>
      </div>

      <div class="vb-plan-live-agent-list"><div class="vb-plan-hub-panel-head compact"><div><span class="eyebrow">Agent operations</span><h2>Agents working now</h2></div><span class="vb-plan-hub-count"><?=$activeAgentCount?> active</span></div><?php if(!empty($commandSnapshot['active_agents'])):?><div class="vb-plan-agent-grid"><?php foreach(array_slice((array)$commandSnapshot['active_agents'],0,12) as $agent):?><a class="vb-plan-agent-card" href="<?=e((string)$agent['url'])?>"><span class="vb-command-agent-state <?=e((string)$agent['status'])?>"><i></i><?=e(ucfirst((string)$agent['status']))?></span><strong><?=e((string)$agent['agent_label'])?></strong><p><?=e((string)$agent['trip_name'])?></p><div><span><?=e((string)($agent['status_text']?:$agent['progress'].'%'))?></span><b><?=(int)$agent['progress']?>%</b></div></a><?php endforeach;?></div><?php else:?><div class="dashboard-card vb-plan-empty"><strong>No agents are actively running.</strong><p>Open an active trip, run a specialist, or accept a Next Move. Live work will appear here.</p></div><?php endif;?></div>
    </section>

  <?php elseif($activeView==='operations'):?>
    <section class="vb-plan-hub-panel" data-plan-view="operations"><div class="vb-plan-hub-panel-head"><div><span class="eyebrow">Trip operations</span><h2>Command Center</h2><p>Approvals, Next Moves, active specialists, booking handoffs and risks are grouped here instead of interrupting Today.</p></div></div><?php require __DIR__.'/partials/trip-command-center.php';?></section>

  <?php else:?>
    <section class="vb-plan-hub-panel" data-plan-view="memory"><div class="vb-plan-hub-panel-head"><div><span class="eyebrow">Memory & signals</span><h2>What Vacation Brain is learning and watching.</h2><p>Traveler Memory and proactive trip intelligence now stay beside the planning workspace.</p></div><div class="vb-plan-hub-actions"><a class="button secondary small" href="<?=e(app_url('traveler-memory.php'))?>">Traveler Memory</a><a class="button secondary small" href="<?=e(app_url('proactive-settings.php'))?>">Proactive settings</a></div></div><?php require __DIR__.'/partials/traveler-memory-summary.php';?><?php require __DIR__.'/partials/proactive-dashboard-summary.php';?><div class="dashboard-card vb-plan-memory-links"><a href="<?=e(app_url('watches.php'))?>"><strong>Travel Watches</strong><span>Weather, fares, events and place-change signals →</span></a><a href="<?=e(app_url('proactive-settings.php'))?>"><strong>Proactive controls</strong><span>Alerts, auto-research and briefing policy →</span></a></div></section>
  <?php endif;?>
</div></section>
<?php if($activeView==='agents'):?><script src="<?=e(app_url('assets/trip-planning-hub.js'))?>"></script><?php endif;?>
<?php if($activeView==='operations'):?><script type="application/json" data-vb-command-config><?=json_encode(['api'=>app_url('api/trip-command-center.php'),'new_trip_url'=>app_url('dream-new.php')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><script src="<?=e(app_url('assets/trip-command-center.js'))?>"></script><?php endif;?>
<?php require __DIR__.'/partials/footer.php';?>