<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/036_travel_watches.sql',
    'app/Services/TravelWatchService.php',
    'api/travel-watches.php',
    'bin/run-travel-watches.php',
    'watches.php',
    'assets/travel-watches.css',
    'assets/travel-watches.js',
    'app/bootstrap.php',
    'partials/footer.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Destination Watches file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/036_travel_watches.sql');
foreach(['CREATE TABLE travel_watches','CREATE TABLE travel_watch_snapshots','CREATE TABLE travel_watch_events','uq_travel_watch_target','uq_travel_watch_event_fingerprint','idx_travel_watch_due','watch_weather','watch_flights','watch_events','watch_places',"'app_version','1.28'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Travel watch migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TravelWatchService.php');
foreach(['class TravelWatchService','function saveTrip','function saveDestination','function toggleDestination','function updateWatch','function runDue','function runOne','TripIntelligenceService','TravelDataProviderService','travel_watch_snapshots','travel_watch_events','NotificationService','trip_agent_messages','fare_drop','weather_alert','new_events','local_change','max(25.0','>=20','count($new)>=2','INSERT IGNORE'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Travel watch engine missing {$needle}\n");exit(1);}}
if(strpos($service,"'flights'=>")!==false&&strpos($service,"target_type']??'')==='trip'")===false){fwrite(STDERR,"Destination-only watches must not call the flight provider without a trip route.\n");exit(1);}

$runner=$read('bin/run-travel-watches.php');
foreach(["PHP_SAPI!=='cli'",'runDue','--limit=','Run System Upgrade'] as $needle){if(strpos($runner,$needle)===false){fwrite(STDERR,"Travel watch worker missing {$needle}\n");exit(1);}}

$api=$read('api/travel-watches.php');
foreach(['toggle_destination','save_trip','update','delete','watchedKeys','destinations','trips','verify_csrf'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Travel watch API missing {$needle}\n");exit(1);}}

$page=$read('watches.php');
foreach(['Destination Watches','Watch a planned trip','Weather','Flights','Events','Local','What the agents noticed','Noise control','run-travel-watches.php','migration 036'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Destination Watches page missing {$needle}\n");exit(1);}}

$js=$read('assets/travel-watches.js');
foreach(['data-vb-watch-config','toggle_destination','data-vb-watch-button','Watch trip','Watching ✓','data-vb-watches-nav','setupNav'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Travel watch browser integration missing {$needle}\n");exit(1);}}

$css=$read('assets/travel-watches.css');
foreach(['travel-watch-card','travel-watch-signals','travel-watch-event','vb-watch-button','trip-watch-shortcut'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Travel watch styling missing {$needle}\n");exit(1);}}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TravelWatchService.php")===false){fwrite(STDERR,"TravelWatchService is not loaded by bootstrap.\n");exit(1);}
$footer=$read('partials/footer.php');foreach(['assets/travel-watches.css','data-vb-watch-config','api/travel-watches.php','assets/travel-watches.js'] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Global Destination Watches integration missing {$needle}\n");exit(1);}}

echo "Destination Watches contract OK\n";
