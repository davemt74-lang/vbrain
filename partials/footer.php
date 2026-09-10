<?php $footerUser=current_user(); $footerPage=basename($_SERVER['PHP_SELF']??''); ?>
</main>
<?php if($footerUser && !in_array($footerPage,['agent.php','match-chat.php'],true)):?>
<form class="global-agent" method="post" action="<?=e(app_url('agent.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input name="message" placeholder="Ask Vacation Brain…" aria-label="Ask Vacation Brain" required><button aria-label="Send">↑</button></form>
<?php endif;?>
<?php if(!$footerUser):?><footer class="site-footer"><div class="shell footer-shell"><div><strong>Vacation Brain</strong><div class="muted small">Daydream more. Work less.</div></div><div class="footer-copy"><?=e(diagnosis_disclaimer())?></div></div></footer><?php endif;?>
<link rel="stylesheet" href="<?=e(app_url('assets/shell-commerce.css'))?>">
<?php require __DIR__.'/shell-actions.php'; ?>
<script src="<?=e(app_url('assets/app.js'))?>"></script>
<script src="<?=e(app_url('assets/shell-commerce.js'))?>"></script>
<?php if($footerUser && $footerPage==='destination-report.php' && !empty($destinationId)):?>
<script>(function(){const row=document.querySelector('.report-action-row');if(!row||row.querySelector('[data-show-me-there]'))return;const link=document.createElement('a');link.className='button secondary small';link.setAttribute('data-show-me-there','');link.href=<?=json_encode(app_url('vacation-yourself.php?destination_id='.(int)$destinationId))?>;link.textContent='Show Me There';row.appendChild(link);})();</script>
<?php endif;?>
</body></html>
