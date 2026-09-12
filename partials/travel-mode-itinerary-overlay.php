<?php
declare(strict_types=1);
if(!isset($footerUser))return;$overlayTripId=(int)($_GET['id']??0);if($overlayTripId<1)return;
try{$overlayService=new TripItineraryIntelligenceService(db());if(!$overlayService->ready())return;$overlay=$overlayService->snapshot((int)$footerUser['id'],$overlayTripId,(string)($_GET['date']??''),false);}catch(Throwable){return;}
$overlayIssues=array_values(array_filter((array)($overlay['issues']??[]),static fn(array $i):bool=>in_array((string)$i['severity'],['high','medium'],true)));
$overlayNext=array_values(array_filter((array)($overlay['timeline']??[]),static fn(array $e):bool=>in_array((string)$e['phase'],['now','next','later'],true)));
?>
<section class="travel-side-card vb-travel-itinerary-overlay" data-vb-travel-itinerary-overlay>
  <div class="travel-section-head"><span class="eyebrow">Timing Intelligence</span><h2>Door-to-door plan</h2><p>Saved itinerary math only—no traffic, provider or device-location refresh.</p></div>
  <div class="vb-travel-itinerary-overlay-stats"><span><strong><?=count($overlayIssues)?></strong> timing issue<?=count($overlayIssues)===1?'':'s'?></span><a href="<?=e(app_url('trip-itinerary.php?id='.$overlayTripId.'&date='.rawurlencode((string)$overlay['selected_date'])))?>">Full timeline →</a></div>
  <?php if($overlayIssues):?><div class="vb-travel-itinerary-overlay-alerts"><?php foreach(array_slice($overlayIssues,0,2) as $issue):?><article class="severity-<?=e((string)$issue['severity'])?>"><strong><?=e((string)$issue['title'])?></strong><span><?=e((string)$issue['body'])?></span></article><?php endforeach;?></div><?php endif;?>
  <div class="vb-travel-itinerary-overlay-next">
    <?php foreach(array_slice($overlayNext,0,3) as $entry):?><article><span><?=e((string)$entry['time_label'])?></span><div><strong><?=e((string)$entry['title'])?></strong><?php if(!empty($entry['leave_by'])):?><small>Leave by <?=e(date('g:i A',strtotime((string)$entry['leave_by'])))?></small><?php elseif(!empty($entry['arrive_by'])):?><small>Target arrival <?=e(date('g:i A',strtotime((string)$entry['arrive_by'])))?></small><?php endif;?></div></article><?php endforeach;?>
    <?php if(!$overlayNext):?><p class="muted small">No remaining timed itinerary items for this day.</p><?php endif;?>
  </div>
</section>
<script>(function(){var overlay=document.querySelector('[data-vb-travel-itinerary-overlay]'),grid=document.querySelector('.travel-mode-grid'),side=document.querySelector('.travel-mode-side');if(!overlay)return;if(side){side.insertBefore(overlay,side.firstChild);return;}if(grid)grid.insertAdjacentElement('beforebegin',overlay);})();</script>
