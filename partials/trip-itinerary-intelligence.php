<?php
declare(strict_types=1);
if(!isset($footerUser)||!$footerUser)return;$timelineTripId=(int)($_GET['id']??0);if($timelineTripId<1)return;
$timelineSnapshot=null;$timelineError='';
try{$timelineService=new TripItineraryIntelligenceService(db());$timelineSnapshot=$timelineService->snapshot((int)$footerUser['id'],$timelineTripId,(string)($_GET['itinerary_date']??''),true);}catch(Throwable $e){$timelineError=$e->getMessage();}
if(!$timelineSnapshot)return;$timelineSummary=$timelineSnapshot['summary']??[];$timelineDate=(string)($timelineSnapshot['selected_date']??date('Y-m-d'));
?>
<section class="vb-itinerary-intelligence-wrap" data-itinerary-intelligence>
  <div class="vb-itinerary-head">
    <div><span class="eyebrow">Complete itinerary intelligence</span><h2>One trip. One actual timeline.</h2><p>Confirmed bookings are fixed anchors. Flexible plans are sequenced around them with transfer time, preparation buffers and conflict checks.</p></div>
    <div class="vb-itinerary-head-actions"><a class="button secondary small" href="<?=e(app_url('trip-itinerary.php?id='.$timelineTripId.'&date='.rawurlencode($timelineDate)))?>">Open full timeline</a><a class="button secondary small" href="<?=e(app_url('travel-mode.php?id='.$timelineTripId.'&date='.rawurlencode($timelineDate)))?>">Travel Mode</a></div>
  </div>
  <?php if($timelineError):?><div class="alert error"><?=e($timelineError)?></div><?php endif;?>
  <?php if(empty($timelineSnapshot['ready'])):?><div class="trip-intel-alert"><strong>Complete Itinerary Intelligence needs migration 055 / app v1.47.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php else:?>
  <div class="vb-itinerary-stats">
    <article><strong><?=(int)($timelineSummary['fixed']??0)?></strong><span>Fixed anchors</span></article><article><strong><?=(int)($timelineSummary['flexible']??0)?></strong><span>Flexible plans</span></article><article class="<?=((int)($timelineSummary['high_issues']??0)>0)?'danger':''?>"><strong><?=(int)($timelineSummary['high_issues']??0)?></strong><span>High conflicts</span></article><article><strong><?=(int)($timelineSummary['open_gaps']??0)?></strong><span>Open gaps</span></article>
  </div>
  <nav class="vb-itinerary-days" aria-label="Itinerary days">
    <?php foreach(array_slice((array)($timelineSnapshot['dates']??[]),0,14) as $date):?><a class="<?=$date===$timelineDate?'active':''?>" href="<?=e(app_url('dream-trip.php?id='.$timelineTripId.'&workspace=timeline&itinerary_date='.$date))?>"><strong><?=e(date('D',strtotime($date)))?></strong><span><?=e(date('M j',strtotime($date)))?></span></a><?php endforeach;?>
  </nav>
  <?php if(!empty($timelineSnapshot['issues'])):?><div class="vb-itinerary-issues"><?php foreach(array_slice((array)$timelineSnapshot['issues'],0,4) as $issue):?><article class="severity-<?=e((string)$issue['severity'])?>"><span><?=e(strtoupper((string)$issue['severity']))?></span><div><strong><?=e((string)$issue['title'])?></strong><p><?=e((string)$issue['body'])?></p></div></article><?php endforeach;?></div><?php endif;?>
  <div class="vb-itinerary-timeline">
    <?php foreach((array)($timelineSnapshot['timeline']??[]) as $entry):?>
      <article class="vb-itinerary-row <?=!empty($entry['fixed'])?'fixed':'flexible'?>">
        <div class="vb-itinerary-time"><strong><?=e((string)$entry['time_label'])?></strong><span><?=!empty($entry['fixed'])?'Fixed':'Flexible'?></span></div>
        <div class="vb-itinerary-marker"><i></i></div>
        <div class="vb-itinerary-copy"><div class="vb-itinerary-title"><strong><?=e((string)$entry['title'])?></strong><span><?=e(ucwords(str_replace('_',' ',(string)$entry['type'])))?></span></div><?php $loc=trim((string)($entry['location']??''));$addr=trim((string)($entry['address']??''));if($loc||$addr):?><p><?=e($loc.($loc&&$addr?' · ':'').$addr)?></p><?php endif;?><?php if(!empty($entry['transition'])):?><small><?=e((string)$entry['transition']['label'])?><?php if(!empty($entry['leave_by'])):?> · Leave by <?=e(date('g:i A',strtotime((string)$entry['leave_by'])))?><?php endif;?><?php if(!empty($entry['arrive_by'])):?> · Target arrival <?=e(date('g:i A',strtotime((string)$entry['arrive_by'])))?><?php endif;?></small><?php elseif(!empty($entry['arrive_by'])):?><small>Target arrival <?=e(date('g:i A',strtotime((string)$entry['arrive_by'])))?></small><?php endif;?></div>
      </article>
    <?php endforeach;?>
    <?php if(empty($timelineSnapshot['timeline'])):?><div class="vb-itinerary-empty"><strong>No timed plan for <?=e(date('M j',$timelineDate?strtotime($timelineDate):time()))?>.</strong><span>Add itinerary ideas or confirmed bookings and Vacation Brain will assemble the day.</span><a class="button secondary small" href="<?=e(app_url('trip-itinerary.php?id='.$timelineTripId.'&date='.rawurlencode($timelineDate)))?>">Build this day</a></div><?php endif;?>
  </div>
  <?php if(!empty($timelineSnapshot['gaps'])):?><div class="vb-itinerary-gap-row"><?php foreach(array_slice((array)$timelineSnapshot['gaps'],0,3) as $gap):?><a href="<?=e(app_url('local-concierge.php?id='.$timelineTripId))?>"><strong><?=e((string)$gap['title'])?> · <?=round((int)$gap['minutes']/60,1)?>h</strong><span><?=e((string)$gap['body'])?> Ask Local Agent for an option.</span></a><?php endforeach;?></div><?php endif;?>
  <?php endif;?>
</section>
