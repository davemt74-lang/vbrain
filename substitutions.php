<?php
require __DIR__.'/app/bootstrap.php';
$service=new SubstitutionService(db());
$items=$service->published();
$title='Vacation Substitutions — Vacation Brain'; require __DIR__.'/partials/header.php';
?>
<section class="section soft"><div class="shell"><div class="eyebrow">Vacation Substitutions</div><h1 class="page-title">Can’t leave town? Fake it better.</h1><p class="section-lead">Local pretend-vacation plans for when your Vacation Brain is ready but your calendar, wallet, or airline status is not.</p>
<div class="sub-grid"><?php foreach($items as $item): $meta=json_decode((string)$item['metadata_json'],true)?:[];?><a class="sub-card" href="<?=e(app_url('substitution.php?slug='.urlencode($item['slug'])))?>"><div class="sub-theme"><?=e($item['theme'])?></div><h2><?=e($item['name'])?></h2><p><?=e($item['description'])?></p><div class="sub-meta"><span><?= (int)$item['duration_minutes'] ?> min</span><span>Budget <?= str_repeat('$',max(1,(int)$item['budget_level'])) ?></span></div><div class="agent-quote"><?=e($meta['agent_line']??'We are manufacturing a vacation.')?></div></a><?php endforeach;?></div>
</div></section><?php require __DIR__.'/partials/footer.php';?>
