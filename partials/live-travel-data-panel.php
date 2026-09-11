<?php
if(!isset($userId))return;
$livePanelTripId=(int)($_GET['id']??($id??$tripId??0));if($livePanelTripId<1)return;
try{$livePanelDashboard=(isset($dashboard)&&is_array($dashboard)&&((int)($dashboard['trip']['id']??0)===$livePanelTripId))?$dashboard:(new TripIntelligenceService(db()))->dashboard((int)$userId,$livePanelTripId);$livePanelHealth=(new TripLiveIntelligenceService(db()))->health($livePanelDashboard);}catch(Throwable $e){error_log('Live Travel Data panel failed: '.$e->getMessage());return;}
$liveLabels=['flights'=>'Flights','weather'=>'Weather','lodging'=>'Lodging','events'=>'Events','places'=>'Places'];
$liveLinks=['flights'=>app_url('dream-trip.php?id='.$livePanelTripId.'&tab=flights'),'weather'=>app_url('dream-trip.php?id='.$livePanelTripId.'&tab=weather'),'lodging'=>app_url('trip-lodging.php?id='.$livePanelTripId),'events'=>app_url('dream-trip.php?id='.$livePanelTripId.'&tab=events'),'places'=>app_url('dream-trip.php?id='.$livePanelTripId.'&tab=local')];
?>
<section class="dashboard vb-live-data-wrap"><div class="shell"><div class="vb-live-data-panel">
  <div class="vb-live-data-head"><div><span class="eyebrow">Live Trip Data</span><h2>What Vacation Brain knows right now.</h2><p>Live, cached and indicative sources stay visibly separate so planning data never quietly turns into a booking claim.</p></div><a class="button secondary small vb-live-data-link" href="<?=e(app_url('admin/travel-providers.php'))?>" <?=is_admin()?'':'hidden'?>>Provider settings</a></div>
  <div class="vb-live-data-grid">
    <?php foreach(['flights','weather','lodging','events','places'] as $type):$h=$livePanelHealth[$type]??['state'=>'waiting','label'=>'Waiting','provider'=>'','freshness'=>'No snapshot'];?>
    <a class="vb-live-data-card" href="<?=e($liveLinks[$type])?>"><div class="vb-live-data-card-top"><strong><?=e($liveLabels[$type])?></strong><span class="vb-live-data-state <?=e((string)$h['state'])?>"><?=e((string)$h['label'])?></span></div><span class="vb-live-data-provider"><?=e((string)$h['provider'])?></span><small class="vb-live-data-freshness"><?=e((string)$h['freshness'])?><?=!empty($h['stale'])?' · refresh due':''?></small></a>
    <?php endforeach;?>
  </div>
</div></div></section>
