<?php
require __DIR__.'/../app/bootstrap.php';
$adminId=require_admin();$pdo=db();
if(!db_table_exists('destination_reports') || !db_column_exists('users','is_sample')) redirect('upgrade.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $enabled=!empty($_POST['enabled']);
    set_site_setting('sample_data.enabled',$enabled?'1':'0','sample_data',$adminId);
    flash('success',$enabled?'Sample data is now visible across Vacation Brain.':'Sample data is now hidden. No sample records were deleted.');
    redirect('admin/sample-data.php');
}
$enabled=sample_data_enabled();
$counts=[
 'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_sample=1')->fetchColumn(),
 'profiles'=>(int)$pdo->query('SELECT COUNT(*) FROM travel_match_profiles tmp JOIN users u ON u.id=tmp.user_id WHERE u.is_sample=1')->fetchColumn(),
 'destinations'=>(int)$pdo->query('SELECT COUNT(*) FROM destination_catalog WHERE is_sample=1')->fetchColumn(),
 'reports'=>(int)$pdo->query('SELECT COUNT(*) FROM destination_reports WHERE is_sample=1')->fetchColumn(),
 'merch'=>(int)$pdo->query('SELECT COUNT(*) FROM merch_catalog_products WHERE is_sample=1')->fetchColumn(),
 'places'=>(int)$pdo->query('SELECT COUNT(*) FROM places WHERE is_sample=1')->fetchColumn(),
 'entities'=>(int)$pdo->query('SELECT COUNT(*) FROM destination_research_entities e JOIN destination_reports dr ON dr.id=e.report_id WHERE dr.is_sample=1')->fetchColumn(),
 'dashboard_trips'=>db_table_exists('dashboard_trip_suggestions')?(int)$pdo->query('SELECT COUNT(*) FROM dashboard_trip_suggestions WHERE is_sample=1')->fetchColumn():0,
];
$success=flash('success');$title='Sample Data — Vacation Brain';require __DIR__.'/../partials/header.php';
?>
<section class="dashboard"><div class="shell"><div class="dashboard-head"><div><div class="eyebrow">Admin · Sample Data</div><h1>Demo the whole system.</h1><p class="muted">Sample records are real database rows marked as sample. Turning them off hides them from user-facing discovery without deleting them.</p></div><a class="button secondary small" href="<?=e(app_url('admin/index.php'))?>">Admin home</a></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
<div class="sample-toggle-card dashboard-card"><div><h2>Sample data is <?=$enabled?'ON':'OFF'?></h2><p class="muted">Profiles, dashboard trips, destination listings, demo destination reports, and merch catalog items <?=$enabled?'are currently visible':'are currently hidden'?>.</p></div><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><label class="sample-switch"><input type="checkbox" name="enabled" value="1" <?=$enabled?'checked':''?>><span></span><b>Show sample data</b></label><button class="button primary small">Save setting</button></form></div>
<div class="admin-stats" style="margin-top:20px"><div class="info-card"><strong><?=$counts['users']?></strong><span>sample users</span></div><div class="info-card"><strong><?=$counts['profiles']?></strong><span>Travel Match profiles</span></div><div class="info-card"><strong><?=$counts['dashboard_trips']?></strong><span>dashboard trips</span></div><div class="info-card"><strong><?=$counts['destinations']?></strong><span>destinations</span></div><div class="info-card"><strong><?=$counts['reports']?></strong><span>destination reports</span></div><div class="info-card"><strong><?=$counts['merch']?></strong><span>merch products</span></div><div class="info-card"><strong><?=$counts['places']?></strong><span>catalog places</span></div><div class="info-card"><strong><?=$counts['entities']?></strong><span>research entities</span></div></div>
<div class="dashboard-card" style="margin-top:20px"><h2>What this sample pack includes</h2><div class="admin-quick-grid"><div class="info-card"><h3>10 Travel Match people</h3><p>Complete 18+ opt-in profiles, generated profile photos, prompts, travel traits, Dream Trips, and questionnaire activity.</p></div><div class="info-card"><h3>8 dashboard escapes</h3><p>Four Phoenix-area local day trips plus Flagstaff, Scottsdale, Las Vegas, and Palm Springs weekend getaways for the logged-in dashboard.</p></div><div class="info-card"><h3>10 visual destinations</h3><p>Santorini, Kyoto, Tulum, Aspen, Bali, Amalfi Coast, Maui, Paris, Sedona, and Costa Rica with generated listing imagery.</p></div><div class="info-card"><h3>Destination-report demos</h3><p>Each curated destination gets a sample report layout so the research UI is useful before the first live LLM web-research request.</p></div><div class="info-card"><h3>Expanded merch</h3><p>Tees, hoodies, cap, mug, tote, crewneck, stickers and tumbler entries for the ecommerce shell.</p></div></div><p class="microcopy">Sample accounts use non-public <code>@vacationbrain.local</code> addresses and an unknown random password hash. They are intended for discovery/demo data, not login.</p></div></div></section>
<?php require __DIR__.'/../partials/footer.php';?>
