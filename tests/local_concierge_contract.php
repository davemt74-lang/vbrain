<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/050_local_concierge.sql',
    'app/Services/LocalConciergeService.php',
    'local-concierge.php',
    'assets/local-concierge.js',
    'assets/local-concierge.css',
    'app/Services/LiveTravelAgentContextService.php',
    'app/Services/VacationAgentService.php',
    'partials/proactive-trip-panel.php',
    'shared-trip.php',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Local Concierge file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/050_local_concierge.sql');
foreach(['CREATE TABLE trip_local_concierge_runs','CREATE TABLE trip_local_concierge_actions',"ENUM('destination','device')","ENUM('now','next_4_hours','tonight','today','tomorrow')",'UNIQUE KEY uq_local_concierge_action',"'app_version','1.42'"] as $needle){if(strpos($m,$needle)===false){fwrite(STDERR,"Local Concierge migration missing {$needle}\n");exit(1);}}
if(preg_match('/\b(latitude|longitude|device_lat|device_lng|coordinates_json)\b/i',$m)){fwrite(STDERR,"Migration 050 must not persist exact device coordinates.\n");exit(1);}

$service=$read('app/Services/LocalConciergeService.php');
foreach(['class LocalConciergeService','function access','function refresh','function latest','function addSuggestion','function dismissSuggestion','function agentContext','function fallback','anchor_mode','Current location','destination_latitude','destination_longitude','LiveTravelDataProviderService','appendSavedResearch','providerHealth','Only the trip owner or a Co-planner','Exact device coordinates are never stored','no provider or location refresh from chat','TripCollaborationService',"if(\$mode==='destination'&&count(\$suggestions)<8)",'beginTransaction()','FOR UPDATE','was dismissed from this refresh','inTransaction()'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Local Concierge service missing {$needle}\n");exit(1);}}
if(strpos($service,"'can_add'=>!empty(\$collab['can_plan'])")===false){fwrite(STDERR,"Local Concierge must inherit Co-planner-only itinerary mutation from collaboration capabilities.\n");exit(1);}
if(strpos($service,'TripBookingActionService')!==false||strpos($service,'executeApproved')!==false){fwrite(STDERR,"Local Concierge must not execute provider booking actions.\n");exit(1);}
if(preg_match('/INSERT INTO trip_local_concierge_runs[^;]*(latitude|longitude)/is',$service)){fwrite(STDERR,"Concierge persistence must not write exact device coordinates.\n");exit(1);}

$page=$read('local-concierge.php');
foreach(['Destination & Local Concierge','Use the destination—or your location once','data-use-location','data-anchor-mode','data-latitude','data-longitude','Use trip destination','Use my location once','Provider health','Permission boundary','Add to itinerary','Concierge results are suggestions, not reservations','No provider call happens merely because you opened this page'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Local Concierge UI missing {$needle}\n");exit(1);}}
foreach(['verify_csrf','addSuggestion','dismissSuggestion'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Local Concierge POST boundary missing {$needle}\n");exit(1);}}

$js=$read('assets/local-concierge.js');
foreach(["addEventListener('click'",'navigator.geolocation','getCurrentPosition','data-anchor-mode','latitude','longitude','Coordinates will not be stored'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Local Concierge one-time location JS missing {$needle}\n");exit(1);}}
if((str_contains($js,'DOMContentLoaded')||str_contains($js,'window.onload')||str_contains($js,"addEventListener('load'"))&&str_contains($js,'getCurrentPosition')){fwrite(STDERR,"Device geolocation must not run automatically on page load.\n");exit(1);}

$live=$read('app/Services/LiveTravelAgentContextService.php');
foreach(['LocalConciergeService','agentContext($userId,1)','fallback($userId)','exact device coordinates'] as $needle){if(stripos($live,$needle)===false){fwrite(STDERR,"Main live agent context missing Local Concierge grounding: {$needle}\n");exit(1);}}
if(strpos($live,'->refresh(')!==false){fwrite(STDERR,"Main chat context must never refresh Local Concierge providers.\n");exit(1);}

$agent=$read('app/Services/VacationAgentService.php');
foreach(['When LOCAL CONCIERGE STATE is present','what should we do nearby','what should we do tonight','local concierge','last saved Local Concierge run','This chat did not refresh your location or any provider','Chat itself will not request or refresh device location'] as $needle){if(stripos($agent,$needle)===false){fwrite(STDERR,"Vacation Brain Local Concierge grounding/fallback missing {$needle}\n");exit(1);}}
if(strpos($agent,'LocalConciergeService($this->pdo))->refresh')!==false){fwrite(STDERR,"Vacation Brain chat must not invoke a Local Concierge refresh.\n");exit(1);}

$panel=$read('partials/proactive-trip-panel.php');if(strpos($panel,'local-concierge.php?id=')===false||strpos($panel,'Local Concierge')===false){fwrite(STDERR,"Owner Travel Mode/trip surfaces need a Local Concierge entry point.\n");exit(1);}
$shared=$read('shared-trip.php');if(strpos($shared,'local-concierge.php?id=')===false||strpos($shared,'Local Concierge')===false){fwrite(STDERR,"Shared trip workspace needs a Local Concierge entry point.\n");exit(1);}
$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/LocalConciergeService.php")===false){fwrite(STDERR,"Local Concierge service is not bootstrapped.\n");exit(1);}
$css=$read('assets/local-concierge.css');foreach(['vb-lc-control-card','vb-lc-summary','vb-lc-layout','vb-lc-card','vb-lc-health'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Local Concierge styling missing {$needle}\n");exit(1);}}

echo "Destination & Local Concierge contract OK\n";
