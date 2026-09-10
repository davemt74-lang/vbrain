<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$today = file_get_contents($root.'/today.php');
$header = file_get_contents($root.'/partials/header.php');
$footer = file_get_contents($root.'/partials/footer.php');
$js = file_get_contents($root.'/assets/dashboard-trips.js');
$sql = file_get_contents($root.'/db/031_dashboard_trip_suggestions.sql');
if ($today === false || $header === false || $footer === false || $js === false || $sql === false) {
    fwrite(STDERR, "Could not read dashboard implementation files.\n"); exit(1);
}
$requiredToday = ['Local Day Trips','Weekend Getaways','Multi-Day Excursions','data-map-toggle','vb-daytrip-map','dashboard_trip_suggestions','assets/dashboard-trips.css','assets/dashboard-trips.js'];
foreach ($requiredToday as $needle) {
    if (strpos($today,$needle) === false) { fwrite(STDERR,"Missing dashboard behavior: {$needle}\n"); exit(1); }
}
if (strpos($today,'Answer one ridiculous question') !== false || strpos($today,'Escape for a minute') !== false) {
    fwrite(STDERR,"Legacy Today hero actions must not return.\n"); exit(1);
}
if (strpos($header,'<span>⌂</span>Dashboard') === false) { fwrite(STDERR,"Logged-in sidebar must label today.php as Dashboard.\n"); exit(1); }
if (preg_match('#href="<\?=e\(app_url\(\'swipe\.php\'\)\)\?>"#',$header) || preg_match('#href="<\?=e\(app_url\(\'escape\.php\'\)\)\?>"#',$header)) {
    fwrite(STDERR,"Swipe and Escape must not be primary sidebar entries.\n"); exit(1);
}
if (strpos($footer,'data-vb-agent-config') === false || strpos($footer,'dashboard-agent-bar.js') === false) { fwrite(STDERR,"Dashboard must use the unified Vacation Brain composer.\n"); exit(1); }
foreach (['navigator.geolocation','setView','data-use-location'] as $needle) {
    if (strpos($js,$needle) === false) { fwrite(STDERR,"Missing dashboard map behavior: {$needle}\n"); exit(1); }
}
foreach (['CREATE TABLE IF NOT EXISTS dashboard_trip_suggestions',"'local'","'weekend'",'sedona-red-rock-trails','flagstaff-weekend'] as $needle) {
    if (strpos($sql,$needle) === false) { fwrite(STDERR,"Missing dashboard sample data: {$needle}\n"); exit(1); }
}
echo "Dashboard trip contract OK\n";
