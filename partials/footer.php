<?php $footerUser=current_user(); $footerPage=basename($_SERVER['PHP_SELF']??''); ?>
<?php if($footerUser && $footerPage==='today.php' && isset($pdo,$userId)):?><?php require __DIR__.'/trip-command-center.php';?><?php endif;?>
</main>
<?php if(!$footerUser):?><footer class="site-footer"><div class="shell footer-shell"><div><strong>Vacation Brain</strong><div class="muted small">Daydream more. Work less.</div></div><div class="footer-copy"><?=e(diagnosis_disclaimer())?></div></div></footer><?php endif;?>
<link rel="stylesheet" href="<?=e(app_url('assets/shell-commerce.css'))?>">
<?php if($footerPage==='dream-trip.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-agent-tabs.css'))?>"><?php endif;?>
<?php if($footerUser):?><link rel="stylesheet" href="<?=e(app_url('assets/travel-watches.css'))?>" data-vb-watch-style><?php endif;?>
<?php if($footerUser && in_array($footerPage,['today.php','dream-trip.php'],true)):?><link rel="stylesheet" href="<?=e(app_url('assets/brain-activity.css'))?>" data-vb-brain-style><?php endif;?>
<?php if($footerUser && $footerPage==='today.php'):?><link rel="stylesheet" href="<?=e(app_url('assets/trip-command-center.css'))?>" data-vb-command-style><?php endif;?>
<?php require __DIR__.'/shell-actions.php'; ?>
<script src="<?=e(app_url('assets/app.js'))?>"></script>
<script src="<?=e(app_url('assets/shell-commerce.js'))?>"></script>
<?php if($footerUser):?>
<script type="application/json" data-vb-watch-config><?=json_encode([
    'csrf'=>csrf_token(),
    'api'=>app_url('api/travel-watches.php'),
    'watches_url'=>app_url('watches.php'),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/travel-watches.js'))?>"></script>
<?php endif;?>
<?php if($footerUser && in_array($footerPage,['today.php','dream-trip.php'],true)):?>
<script type="application/json" data-vb-brain-config><?=json_encode([
    'api'=>app_url('api/brain-activity.php'),
    'job_api'=>app_url('api/trip-agent-jobs.php'),
    'csrf'=>csrf_token(),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/brain-activity.js'))?>"></script>
<?php endif;?>
<?php if($footerUser && $footerPage==='today.php'):?>
<script type="application/json" data-vb-command-config><?=json_encode([
    'api'=>app_url('api/trip-command-center.php'),
    'new_trip_url'=>app_url('dream-new.php'),
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="<?=e(app_url('assets/trip-command-center.js'))?>"></script>
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
