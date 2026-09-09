<?php
require __DIR__.'/../app/bootstrap.php';
$adminId=require_admin();
$pdo=db();

$dashboardErrors=[];
$safeCount=static function(PDO $pdo,string $sql,string $label) use (&$dashboardErrors): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        error_log('Vacation Brain admin dashboard metric failed ['.$label.']: '.$e->getMessage());
        $dashboardErrors[]=$label;
        return 0;
    }
};

$stats=[
 'content'=>$safeCount($pdo,"SELECT COUNT(*) FROM content_items WHERE status='published'",'published content'),
 'review'=>$safeCount($pdo,"SELECT COUNT(*) FROM ai_generated_candidates WHERE status='needs_review'",'review queue'),
 'jobs'=>$safeCount($pdo,'SELECT COUNT(*) FROM ai_generation_jobs','generation jobs'),
 'users'=>$safeCount($pdo,"SELECT COUNT(*) FROM users WHERE status='active'",'active users'),
 'match_reports'=>$safeCount($pdo,"SELECT COUNT(*) FROM travel_match_reports WHERE status='open'",'travel match reports'),
];
$stats['destination_reports']=db_table_exists('destination_reports')?$safeCount($pdo,"SELECT COUNT(*) FROM destination_reports WHERE status='ready'",'destination reports'):0;
$stats['sample_users']=db_column_exists('users','is_sample')?$safeCount($pdo,'SELECT COUNT(*) FROM users WHERE is_sample=1','sample users'):0;

$manifest=[];
try {
    $manifest=$pdo->query('SELECT * FROM v_seed_generation_progress ORDER BY content_type')->fetchAll()?:[];
} catch (Throwable $e) {
    error_log('Vacation Brain admin dashboard seed progress failed: '.$e->getMessage());
    $dashboardErrors[]='seed progress';
}
$dashboardErrors=array_values(array_unique($dashboardErrors));

$title='Admin — Vacation Brain';require __DIR__.'/../partials/header.php';
?>
<section class="dashboard"><div class="shell">
<?php if(!db_table_exists('destination_catalog') || !db_table_exists('merch_catalog_products')):?><div class="alert warning" style="margin-bottom:18px"><strong>Database upgrade required.</strong> The new Destinations, Shop, and Brand/PWA features are waiting for migration 022. <a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade →</a></div><?php endif;?>
<?php if($dashboardErrors):?><div class="alert warning" style="margin-bottom:18px"><strong>Some dashboard counters are temporarily unavailable.</strong> The Admin area is still usable. Failed metric<?=count($dashboardErrors)===1?'':'s'?>: <?=e(implode(', ',$dashboardErrors))?>.</div><?php endif;?>
<div class="dashboard-head"><div><div class="eyebrow">Admin</div><h1>Vacation Brain Control Room</h1><p class="muted">Content, AI providers, seed generation, review, users, and Travel Match operations.</p></div><a class="button primary small" href="<?=e(app_url('admin/ai-content.php'))?>">AI Content Factory</a></div>
<div class="admin-stats"><div class="info-card"><strong><?=$stats['content']?></strong><span>published content</span></div><div class="info-card"><strong><?=$stats['review']?></strong><span>waiting for review</span></div><div class="info-card"><strong><?=$stats['jobs']?></strong><span>generation jobs</span></div><div class="info-card"><strong><?=$stats['users']?></strong><span>active users</span></div></div>
<div class="admin-quick-grid" style="margin-top:20px"><?php if(db_table_exists('destination_reports')):?><a class="info-card" href="<?=e(app_url('admin/destination-reports.php'))?>"><h3>Destination Research</h3><p><?=e((string)$stats['destination_reports'])?> saved report snapshots with sources, lodging, restaurants, events and reusable catalog entities.</p></a><?php endif;?><?php if(db_column_exists('users','is_sample')):?><a class="info-card" href="<?=e(app_url('admin/sample-data.php'))?>"><h3>Sample Data</h3><p>Toggle <?=e((string)$stats['sample_users'])?> sample users plus demo destinations, reports and merch without deleting them.</p></a><?php endif;?><a class="info-card" href="<?=e(app_url('admin/users.php'))?>"><h3>Users</h3><p>Review accounts and manage User, Qualified Assessor, and Admin roles.</p></a><a class="info-card" href="<?=e(app_url('admin/destinations.php'))?>"><h3>Destinations</h3><p>Manage the Vacation Brain destination browsing catalog.</p></a><a class="info-card" href="<?=e(app_url('admin/shop.php'))?>"><h3>Merch / Shopping</h3><p>Manage the ecommerce merchandise catalog and pricing.</p></a><a class="info-card" href="<?=e(app_url('admin/brand-pwa.php'))?>"><h3>Brand & PWA</h3><p>Upload the site logo, app icon and splash image, and configure install settings.</p></a><a class="info-card" href="<?=e(app_url('upgrade.php'))?>"><h3>System Upgrade</h3><p>Check for pending SQL migrations and apply them with one click.</p></a><a class="info-card" href="<?=e(app_url('admin/ai-settings.php'))?>"><h3>AI / API Keys</h3><p>Configure OpenAI, Claude/Anthropic, and ElevenLabs providers.</p></a><a class="info-card" href="<?=e(app_url('admin/ai-content.php'))?>"><h3>AI Content Factory</h3><p>Create structured generation jobs and import JSON batches.</p></a><a class="info-card" href="<?=e(app_url('admin/content.php'))?>"><h3>Content Library</h3><p>Search, filter, publish, draft, and retire installed content.</p></a><a class="info-card" href="<?=e(app_url('admin/review-content.php'))?>"><h3>Review Queue</h3><p>Approve, edit, reject, and publish generated candidates.</p></a><a class="info-card" href="<?=e(app_url('admin/places.php'))?>"><h3>Local Guide</h3><p>Add tagged places for Vacation Substitution matching.</p></a><a class="info-card" href="<?=e(app_url('admin/assessments.php'))?>"><h3>Assessment Queue</h3><p>Review qualified professional Vacation Brain assessment requests.</p></a><a class="info-card" href="<?=e(app_url('admin/match-reports.php'))?>"><h3>Travel Match Safety</h3><p><?=e((string)$stats['match_reports'])?> open report<?=((int)$stats['match_reports']===1?'':'s')?> waiting for review.</p></a></div>
<div class="dashboard-card" style="margin-top:20px"><div class="admin-table-head"><div><h2>2,200-record seed plan</h2><p class="muted">Generate, review, and publish toward each launch target.</p></div><a class="link-arrow" href="<?=e(app_url('admin/review-content.php'))?>">Review candidates →</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>Type</th><th>Target</th><th>Approved</th><th>Published</th><th>Remaining</th></tr></thead><tbody><?php foreach($manifest as $row):?><tr><td><?=e($row['content_type'])?></td><td><?=(int)$row['target_count']?></td><td><?=(int)$row['approved_candidates']?></td><td><?=(int)$row['published_candidates']?></td><td><?=(int)$row['remaining_to_approve']?></td></tr><?php endforeach;?><?php if(!$manifest):?><tr><td colspan="5" class="muted">Seed progress is currently unavailable.</td></tr><?php endif;?></tbody></table></div></div>
</div></section><?php require __DIR__.'/../partials/footer.php';?>
