<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$today = file_get_contents($root.'/today.php');
$header = file_get_contents($root.'/partials/header.php');
$api = file_get_contents($root.'/api/dashboard-tabs.php');
$js = file_get_contents($root.'/assets/dashboard-tabs.js');
$css = file_get_contents($root.'/assets/dashboard-tabs.css');
$sql = file_get_contents($root.'/db/032_dashboard_agent_tabs.sql');
if ($today === false || $header === false || $api === false || $js === false || $css === false || $sql === false) {
    fwrite(STDERR,"Could not read dashboard agent tab implementation files.\n");
    exit(1);
}

foreach (['data-agent-workspace','data-agent-tab','data-agent-add','data-agent-tab-settings','data-tab-settings-drawer','Main','Plan a Trip','Create a Photo Album','Research a Destination','Build an Itinerary'] as $needle) {
    if (strpos($today,$needle) === false) { fwrite(STDERR,"Missing dashboard tab UI: {$needle}\n"); exit(1); }
}
if (strpos($today,'vb-dashboard-icon') !== false) {
    fwrite(STDERR,"Dashboard section heading icons must remain removed.\n"); exit(1);
}
if (strpos($header,'>Plan Trip</a>') === false) {
    fwrite(STDERR,"Dream sidebar link must be labeled Plan Trip.\n"); exit(1);
}
if (strpos($header,'brand-mark') !== false) {
    fwrite(STDERR,"Fallback Vacation Brain logo mark must remain removed.\n"); exit(1);
}
foreach (['require_auth()','dashboard_agent_tabs',"$action === 'create'","$action === 'update'","$action === 'delete'",'hash_equals','WHERE id=? AND user_id=?'] as $needle) {
    if (strpos($api,$needle) === false) { fwrite(STDERR,"Missing agent-tab API behavior: {$needle}\n"); exit(1); }
}
foreach (['CREATE TABLE IF NOT EXISTS dashboard_agent_tabs','FOREIGN KEY (user_id)','settings_json'] as $needle) {
    if (strpos($sql,$needle) === false) { fwrite(STDERR,"Missing agent-tab schema behavior: {$needle}\n"); exit(1); }
}
foreach (['selectTab','openCreate','openAgentSettings','data-tab-delete',"payload.set('action','delete')",'vb:dashboard-main-shown'] as $needle) {
    if (strpos($js,$needle) === false) { fwrite(STDERR,"Missing agent-tab JavaScript behavior: {$needle}\n"); exit(1); }
}
foreach (['.vb-agent-tabbar','.vb-agent-tab.active','.vb-tab-settings-drawer','.vb-agent-action-grid'] as $needle) {
    if (strpos($css,$needle) === false) { fwrite(STDERR,"Missing agent-tab styling: {$needle}\n"); exit(1); }
}

echo "Dashboard agent tabs contract OK\n";
