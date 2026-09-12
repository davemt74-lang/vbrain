<?php $footerUser=current_user(); $footerPage=basename($_SERVER['PHP_SELF']??''); ?>
<?php if($footerUser && in_array($footerPage,['dream-trip.php','travel-mode.php'],true) && (int)($_GET['id']??0)>0):?><?php require __DIR__.'/live-travel-data-panel.php';?><?php require __DIR__.'/proactive-trip-panel.php';?><?php endif;?>
<?php if($footerUser && $footerPage==='dream-trip.php' && (int)($_GET['id']??0)>0):?><?php require __DIR__.'/trip-itinerary-intelligence.php';?><?php endif;?>
<?php if($footerUser && $footerPage==='travel-mode.php' && (int)($_GET['id']??0)>0):?><?php require __DIR__.'/travel-mode-spend-panel.php';?><?php require __DIR__.'/travel-mode-itinerary-overlay.php';?><?php endif;?>
<?php if($footerUser && $footerPage==='trip-bookings.php' && isset($userId,$tripId) && (int)$tripId>0):?><?php require __DIR__.'/flight-tracking-panel.php';?><?php endif;?>
</main>
<?php if(!$footerUser):?><footer class="site-footer"><div class="shell footer-shell"><div><strong>Vacation Brain</strong><div class="muted small">Daydream more. Work less.</div></div><div class="footer-copy"><?=e(diagnosis_disclaimer())?></div></div></footer><?php endif;?>
<link rel="stylesheet" href="<?=e(app_url('assets/shell-commerce.css'))?>">
<?php if($footerPage==='dream-trip.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-agent-tabs.css'))?>"><?php endif;?>
<?php if($footerPage==='dream-trip.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-bookings.css'))?>" data-vb-booking-style><?php endif;?>
<?php if($footerPage==='dream-trip.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-workspace-tabs.css'))?>" data-vb-trip-workspace-style><?php endif;?>
<?php if($footerUser && in_array($footerPage,['dream-trip.php','trip-itinerary.php','travel-mode.php'],true)):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-itinerary-intelligence.css'))?>" data-vb-itinerary-style><?php endif;?>
<?php if($footerUser):?><link rel="stylesheet" href="<?=e(app_url('assets/travel-watches.css'))?>" data-vb-watch-style><?php endif;?>
<?php if($footerUser && $footerPage==='dream-trip.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/brain-activity.css'))?>" data-vb-brain-style><?php endif;?>
<?php if($footerUser && in_array($footerPage,['dream-trip.php','travel-mode.php','trip-bookings.php','trip-lodging.php'],true)):?><link rel="stylesheet" href="<?=e(app_url('assets/live-travel-data.css'))?>" data-vb-live-data-style><?php endif;?>
<?php if($footerUser && in_array($footerPage,['dream-trip.php','travel-mode.php','proactive-trip.php','proactive-settings.php'],true)):?><link rel="stylesheet" href="<?=e(app_url('assets/proactive-travel.css'))?>" data-vb-proactive-style><?php endif;?>
<?php require __DIR__.'/shell-actions.php'; ?>
<script src="<?=e(app_url('assets/app.js'))?>"></script>
<script src="<?=e(app_url('assets/shell-commerce.js'))?>"></script>
<?php if($footerUser && $footerPage==='local-concierge.php'):?><script src="<?=e(app_url('assets/local-concierge.js'))?>"></script><?php endif;?>
<?php if($footerUser):?>
<script type="application/json" data-vb-watch-config><?=json_encode([
    'csrf'=>csrf_token(),
    'api'=>app_url('api/travel-watches.php'),
    'watches_url'=>app_url('watches.php'),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/travel-watches.js'))?>"></script>
<?php endif;?>
<?php if($footerUser && $footerPage==='dream-trip.php'):?><script src="<?=e(app_url('assets/live-travel-data.js'))?>"></script><?php endif;?>
<?php if($footerUser && $footerPage==='dream-trip.php'):?>
<script type="application/json" data-vb-brain-config><?=json_encode([
    'api'=>app_url('api/brain-activity.php'),
    'job_api'=>app_url('api/trip-agent-jobs.php'),
    'csrf'=>csrf_token(),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/brain-activity.js'))?>"></script>
<script src="<?=e(app_url('assets/trip-workspace-tabs.js'))?>"></script>
<?php endif;?>
<?php if($footerUser && $footerPage==='dream-trip.php' && (int)($_GET['id']??0)>0): $footerTripId=(int)$_GET['id'];?>
<script type="application/json" data-vb-booking-summary-config><?=json_encode([
    'api'=>app_url('api/trip-bookings.php'),
    'trip_id'=>$footerTripId,
    'manage_url'=>app_url('trip-bookings.php?id='.$footerTripId),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/trip-booking-summary.js'))?>"></script>
<?php
$footerTravelProminent=false;$footerTravelLabel='Travel Mode';
try{
    if(db_column_exists('dream_trips','operational_state')){
        $footerTravelStmt=db()->prepare('SELECT operational_state,start_date FROM dream_trips WHERE id=? AND user_id=? LIMIT 1');$footerTravelStmt->execute([$footerTripId,(int)$footerUser['id']]);$footerTravelRow=$footerTravelStmt->fetch();
        if($footerTravelRow){$footerState=(string)($footerTravelRow['operational_state']??'planning');$footerStart=strtotime((string)($footerTravelRow['start_date']??''));$footerDays=$footerStart!==false?(int)floor(($footerStart-strtotime(date('Y-m-d')))/86400):999;$footerTravelProminent=in_array($footerState,['ready','traveling'],true)||($footerDays>=0&&$footerDays<=7);if($footerState==='traveling')$footerTravelLabel='Travel Mode · Live';elseif($footerState==='ready')$footerTravelLabel='Travel Mode · Ready';}
    }
}catch(Throwable $e){}
?>
<script>(function(){const row=document.querySelector('.trip-intelligence-head-actions');if(!row||row.querySelector('[data-travel-mode-link]'))return;const link=document.createElement('a');link.className=<?=json_encode($footerTravelProminent?'button primary small':'button secondary small')?>;link.setAttribute('data-travel-mode-link','');link.href=<?=json_encode(app_url('travel-mode.php?id='.$footerTripId))?>;link.textContent=<?=json_encode($footerTravelLabel)?>;row.prepend(link);})();</script>
<?php endif;?>
<?php if($footerUser && $footerPage!=='match-chat.php'):
$footerAgentPrefill=(string)($agentComposerPrefill??'');
if($footerAgentPrefill==='' && $footerPage==='agent.php'){
    $footerPromptMap=['profile'=>'What have you learned about me?','diagnosis'=>'Show me what my Vacation Brain would prescribe.','gallery'=>'Show my fake vacations.'];
    $footerAgentPrefill=$footerPromptMap[(string)($_GET['prompt']??'')]??'';
}
$footerAgentAction=(string)($agentComposerActionUrl??app_url('agent.php'));
$footerAgentFields=is_array($agentComposerFields??null)?$agentComposerFields:[];
$footerAgentContextLabel=trim((string)($agentComposerContextLabel??''));
$footerAgentPlaceholder=trim((string)($agentComposerPlaceholder??'Ask Vacation Brain…'));
$footerAgentTaskMode=trim((string)($agentComposerTaskMode??'default'));
?>
<link rel="stylesheet" href="<?=e(app_url('assets/dashboard-agent-bar.css'))?>" data-vb-agent-bar-style>
<script type="application/json" data-vb-agent-config><?=json_encode([
    'csrf'=>csrf_token(),
    'agent_url'=>$footerAgentAction,
    'extra_fields'=>$footerAgentFields,
    'fixed_context_label'=>$footerAgentContextLabel,
    'placeholder'=>$footerAgentPlaceholder,
    'task_mode'=>$footerAgentTaskMode,
    'context_api'=>app_url('api/dashboard-destination-context.php'),
    'photos_url'=>app_url('photos.php'),
    'vacation_yourself_url'=>app_url('vacation-yourself.php'),
    'prefill'=>$footerAgentPrefill,
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/dashboard-agent-bar.js'))?>"></script>
<?php endif;?>
<?php if($footerUser && $footerPage==='destination-report.php' && !empty($destinationId)):?>
<script>(function(){const row=document.querySelector('.report-action-row');if(!row||row.querySelector('[data-show-me-there]'))return;const link=document.createElement('a');link.className='button secondary small';link.setAttribute('data-show-me-there','');link.href=<?=json_encode(app_url('vacation-yourself.php?destination_id='.(int)$destinationId))?>;link.textContent='Show Me There';row.appendChild(link);})();</script>
<?php endif;?>
</body></html>