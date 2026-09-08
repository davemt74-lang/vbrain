<?php $footerUser=current_user(); ?>
</main>
<?php if($footerUser && !in_array(basename($_SERVER['PHP_SELF']??''),['agent.php','match-chat.php'],true)):?>
<form class="global-agent" method="post" action="<?=e(app_url('agent.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input name="message" placeholder="Ask Vacation Brain…" aria-label="Ask Vacation Brain" required><button aria-label="Send">↑</button></form>
<?php endif;?>
<?php if(!$footerUser):?><footer class="site-footer"><div class="shell footer-shell"><div><strong>Vacation Brain</strong><div class="muted small">Daydream more. Work less.</div></div><div class="footer-copy"><?=e(diagnosis_disclaimer())?></div></div></footer><?php endif;?>
<script src="<?=e(app_url('assets/app.js'))?>"></script>
</body></html>
