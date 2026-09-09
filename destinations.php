<?php
require __DIR__.'/app/bootstrap.php';
require_auth();$pdo=db();
if (!db_table_exists('destination_catalog')) {
    $title='Destinations — Vacation Brain'; require __DIR__.'/partials/header.php'; ?>
    <section class="dashboard"><div class="shell"><div class="dashboard-card upgrade-required-card"><div class="eyebrow">Explore</div><h1>Destinations are almost ready.</h1><p class="muted">The destination catalog needs the latest Vacation Brain database upgrade before it can load.</p><?php if(can_show_admin_entry()):?><a class="button primary" href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?><p class="microcopy">An administrator needs to run the pending system upgrade.</p><?php endif;?></div></div></section>
    <?php require __DIR__.'/partials/footer.php'; exit;
}
$q=trim((string)($_GET['q']??''));
$sampleClause=(db_column_exists('destination_catalog','is_sample')&&!sample_data_enabled())?' AND d.is_sample=0':'';
$hasGallery=db_table_exists('destination_gallery_images');
$galleryHasRole=$hasGallery&&db_column_exists('destination_gallery_images','image_role');
if($hasGallery){
    $galleryOrder=$galleryHasRole?"CASE WHEN COALESCE(gi.image_role,'')='hero' THEN 0 ELSE 1 END,":"";
    $displayImageSelect="COALESCE(NULLIF(d.hero_image_url,''),(SELECT gi.image_url FROM destination_gallery_images gi WHERE gi.destination_catalog_id=d.id ORDER BY {$galleryOrder}gi.sort_order ASC,gi.id ASC LIMIT 1)) AS display_image_url";
}else{
    $displayImageSelect="d.hero_image_url AS display_image_url";
}
$sql="SELECT d.*, $displayImageSelect FROM destination_catalog d WHERE d.status='active'".$sampleClause;$args=[];
if($q!==''){$sql.=' AND (d.name LIKE ? OR d.city LIKE ? OR d.region LIKE ? OR d.country LIKE ? OR d.best_for LIKE ? OR d.vibe LIKE ?)';$like='%'.$q.'%';$args=array_fill(0,6,$like);}
$sql.=' ORDER BY d.featured DESC,d.sort_order ASC,d.name ASC';$stmt=$pdo->prepare($sql);$stmt->execute($args);$rows=$stmt->fetchAll();
$researchReady=db_table_exists('destination_reports');
$title='Destinations — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="dashboard destinations-page"><div class="shell"><div class="dashboard-head"><div><div class="eyebrow">Explore</div><h1>Destinations</h1><p class="muted">Browse places Vacation Brain already knows, or research somewhere completely new and save the result into the system.</p></div><?php if($researchReady):?><a class="button primary small" href="<?=e(app_url('destination-report.php'))?>">Research anywhere</a><?php endif;?></div>
<div class="destination-search-panel"><form class="dashboard-card destination-filter" method="get"><label>Search the catalog<input class="input" type="search" name="q" value="<?=e($q)?>" placeholder="Beach, Paris, food, nightlife…"></label><button class="button secondary small">Search listings</button></form><?php if($researchReady):?><form class="dashboard-card destination-research-inline" method="get" action="<?=e(app_url('destination-report.php'))?>"><div><strong>Not in the catalog?</strong><p class="muted small">Ask Vacation Brain to build and save a full destination research report.</p></div><input class="input" name="q" value="<?=e($q)?>" placeholder="Lisbon, Portugal" required><button class="button primary small">Research with AI</button></form><?php endif;?></div>
<div class="destination-grid"><?php foreach($rows as $d):?><?php $displayImage=(string)($d['display_image_url']??'');if($displayImage!==''&&!local_media_exists($displayImage,__DIR__))$displayImage='';?><article class="destination-card"><?php if($displayImage!==''):?><a class="destination-media" href="<?=e(app_url('destination-report.php?destination_id='.(int)$d['id']))?>" style="background-image:url('<?=e(media_url($displayImage))?>')"></a><?php else:?><a class="destination-media destination-placeholder" href="<?=e(app_url('destination-report.php?destination_id='.(int)$d['id']))?>"><span>☼</span><strong><?=e($d['name'])?></strong></a><?php endif;?><div class="destination-body"><div class="destination-card-meta"><span class="eyebrow"><?=e(trim(implode(' · ',array_filter([$d['city'],$d['region'],$d['country']]))))?></span><?php if(!empty($d['is_sample'])):?><span class="sample-badge">Sample</span><?php endif;?></div><h2><?=e($d['name'])?></h2><p><?=e($d['short_description'])?></p><?php if($d['vibe']):?><div class="tag-row"><span class="badge"><?=e($d['vibe'])?></span></div><?php endif;?><?php if($d['best_for']):?><p class="muted small"><strong>Best for:</strong> <?=e($d['best_for'])?></p><?php endif;?><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px"><a class="button primary small" href="<?=e(app_url('vacation-yourself.php?destination_id='.(int)$d['id']))?>">Show Me There</a><a class="link-arrow" href="<?=e(app_url('destination-report.php?destination_id='.(int)$d['id']))?>">View destination report →</a></div></div></article><?php endforeach;?><?php if(!$rows):?><div class="dashboard-card"><h2>No catalog results.</h2><p class="muted">That does not mean Vacation Brain has given up. Research “<?=e($q?:'somewhere better')?>” and it can become a saved destination.</p><?php if($researchReady&&$q!==''):?><a class="button primary small" href="<?=e(app_url('destination-report.php?q='.urlencode($q)))?>">Research <?=e($q)?> →</a><?php endif;?></div><?php endif;?></div></div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
