<?php $footerUser=current_user(); $footerPage=basename($_SERVER['PHP_SELF']??''); ?>
</main>
<?php if(!$footerUser):?><footer class="site-footer"><div class="shell footer-shell"><div><strong>Vacation Brain</strong><div class="muted small">Daydream more. Work less.</div></div><div class="footer-copy"><?=e(diagnosis_disclaimer())?></div></div></footer><?php endif;?>
<link rel="stylesheet" href="<?=e(app_url('assets/shell-commerce.css'))?>">
<?php require __DIR__.'/shell-actions.php'; ?>
<script src="<?=e(app_url('assets/app.js'))?>"></script>
<script src="<?=e(app_url('assets/shell-commerce.js'))?>"></script>
<?php if($footerUser && $footerPage!=='match-chat.php'):
$footerAgentPrefill=(string)($agentComposerPrefill??'');
if($footerAgentPrefill==='' && $footerPage==='agent.php'){
    $footerPromptMap=['profile'=>'What have you learned about me?','diagnosis'=>'Show me what my Vacation Brain would prescribe.','gallery'=>'Show my fake vacations.'];
    $footerAgentPrefill=$footerPromptMap[(string)($_GET['prompt']??'')]??'';
}
?>
<link rel="stylesheet" href="<?=e(app_url('assets/dashboard-agent-bar.css'))?>" data-vb-agent-bar-style>
<script type="application/json" data-vb-agent-config><?=json_encode([
    'csrf'=>csrf_token(),
    'agent_url'=>app_url('agent.php'),
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
