<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'app/Services/VacationBrainActivityService.php',
    'api/brain-activity.php',
    'assets/brain-activity.css',
    'assets/brain-activity.js',
    'assets/trip-planning-hub.js',
    'app/bootstrap.php',
    'partials/footer.php',
    'today.php',
    'dream.php',
    'dream-trip.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Brain Activity file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$service=$read('app/Services/VacationBrainActivityService.php');
foreach([
    'class VacationBrainActivityService',
    "['overview','weather','flights','events','local','itinerary','budget']",
    'function snapshot','function tripStats','function destinationStats','function watchStats','function agentStats','function intelligenceStats','function itemStats','function userEventStats','function recentSignals','function userEventSignal','function seriesForChannel','dream_trips','dashboard_destination_context','travel_watches','travel_watch_events','trip_agent_messages','trip_intelligence_snapshots','dream_trip_items','user_events','occurred_at>=DATE_SUB(NOW(),INTERVAL 1 DAY)',"'dream_trip_created'","'dream_trip_viewed'","'dream_item_added'","'Trip planned'","'Itinerary updated'",'array_fill(0,24,0)','watch_alerts_24h','agent_actions_24h','provider_refreshes_24h','planning_actions_24h','fresh data','OutOfBoundsException',
] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Brain activity service missing {$needle}\n");exit(1);}}
if(strpos($service,"db_column_exists('user_events','created_at')")!==false){fwrite(STDERR,"Brain activity must use the canonical user_events.occurred_at column.\n");exit(1);}

$api=$read('api/brain-activity.php');foreach(['require_auth','VacationBrainActivityService','trip_id','activity','application/json','Cache-Control','OutOfBoundsException','http_response_code(404)','temporarily unavailable'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Brain activity API missing {$needle}\n");exit(1);}}

$tripJs=$read('assets/brain-activity.js');foreach(['data-vb-brain-config','[data-trip-intelligence]','.trip-agent-tabs','insertAdjacentElement(\'afterend\'','Agent EEG','setTimeout','document.visibilityState'] as $needle){if(strpos($tripJs,$needle)===false){fwrite(STDERR,"Trip Brain Activity UI missing {$needle}\n");exit(1);}}
if(strpos($tripJs,'Math.random')!==false){fwrite(STDERR,"Agent EEG must reflect real activity, not random animation.\n");exit(1);}
$hubJs=$read('assets/trip-planning-hub.js');foreach(['data-plan-brain','data-plan-brain-score','data-plan-brain-channels','api','30000','document.visibilityState'] as $needle){if(strpos($hubJs,$needle)===false){fwrite(STDERR,"Plan Trip account activity UI missing {$needle}\n");exit(1);}}

$css=$read('assets/brain-activity.css');foreach(['vb-brain-shell','vb-brain-wave','vb-brain-agents','vb-brain-agent','vb-trip-eeg','vb-trip-eeg-channels','data-state="working"'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Brain Activity styling missing {$needle}\n");exit(1);}}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/VacationBrainActivityService.php")===false){fwrite(STDERR,"VacationBrainActivityService is not loaded by bootstrap.\n");exit(1);}
$footer=$read('partials/footer.php');foreach(['assets/brain-activity.css','data-vb-brain-config','api/brain-activity.php','assets/brain-activity.js',"$footerPage==='dream-trip.php'"] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Trip Brain Activity footer integration missing {$needle}\n");exit(1);}}
if(strpos($footer,"['today.php','dream-trip.php']")!==false){fwrite(STDERR,"Account Brain Activity must no longer mount on Today.\n");exit(1);}
$dream=$read('dream.php');foreach(['VacationBrainActivityService','Vacation Brain Activity','data-plan-brain','trip-planning-hub.js'] as $needle){if(strpos($dream,$needle)===false){fwrite(STDERR,"Plan Trip Brain Activity mount missing {$needle}\n");exit(1);}}
$today=$read('today.php');$localPos=strpos($today,'vb-local-section');$weekendPos=strpos($today,'Weekend Getaways');if($localPos===false||$weekendPos===false||$localPos>$weekendPos){fwrite(STDERR,"Dashboard Local Day Trips must remain the first travel section.\n");exit(1);}
$trip=$read('dream-trip.php');if(strpos($trip,'trip-agent-tabs')===false){fwrite(STDERR,"Trip workspace agent tabs are missing.\n");exit(1);}
echo "Brain Activity contract OK\n";
