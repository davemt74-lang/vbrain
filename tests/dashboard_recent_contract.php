<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$js = file_get_contents($root.'/assets/dashboard-tabs.js');
$css = file_get_contents($root.'/assets/dashboard-recent.css');
$tabsCss = file_get_contents($root.'/assets/dashboard-tabs.css');
if ($js === false || $css === false || $tabsCss === false) {
    fwrite(STDERR,"Could not read dashboard recent-view files.\n");
    exit(1);
}

foreach (['Recently Viewed','recentStorageKey','saveRecent','renderRecent','day-trip','weekend','multi-day','dashboard-tabs.css','dashboard-recent.css'] as $needle) {
    if (strpos($js,$needle) === false) {
        fwrite(STDERR,"Missing recently viewed behavior: {$needle}\n");
        exit(1);
    }
}
foreach (['.vb-recent-section','.vb-recent-grid','.vb-recent-card','.vb-recent-tag'] as $needle) {
    if (strpos($css,$needle) === false) {
        fwrite(STDERR,"Missing recently viewed styling: {$needle}\n");
        exit(1);
    }
}
if (strpos($tabsCss,'.vb-agent-tabbar') === false) {
    fwrite(STDERR,"Agent tab stylesheet is missing its core tabbar rules.\n");
    exit(1);
}

echo "Dashboard recently viewed contract OK\n";
