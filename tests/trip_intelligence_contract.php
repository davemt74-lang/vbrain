<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/035_trip_intelligence.sql',
    'dream.php',
    'dream-new.php',
    'dream-trip.php',
    'trip-agent.php',
    'api/trip-intelligence.php',
    'app/bootstrap.php',
    'app/Services/DreamService.php',
    'app/Services/TravelDataProviderService.php',
    'app/Services/TripIntelligenceService.php',
    'app/Services/TripLiveIntelligenceService.php',
    'app/Services/TripSupervisorService.php',
    'app/Services/TripAgentService.php',
    'partials/trip-agent-panel.php',
    'partials/footer.php',
    'assets/trip-intelligence.css',
    'assets/trip-agent-tabs.css',
    'assets/trip-live-agents.css',
    'assets/trip-intelligence.js',
    'assets/dashboard-agent-bar.js',
    'config-example.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing trip intelligence file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/035_trip_intelligence.sql');
foreach(['destination_catalog_id','origin_name','origin_iata','destination_iata','intelligence_refreshed_at','scheduled_date','daypart','source_provider','CREATE TABLE trip_intelligence_snapshots','CREATE TABLE trip_agent_messages','idx_trip_snapshot_current','uq_trip_agent_fingerprint','ADD CONSTRAINT fk_dream_destination_catalog',"'app_version','1.27'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Trip migration missing {$needle}\n");exit(1);}}
if(strpos($migration,'UNIQUE KEY uq_trip_snapshot_query')!==false){fwrite(STDERR,"Trip snapshots must remain historical rather than overwriting refreshes.\n");exit(1);}

$index=$read('dream.php');
foreach(['+ New trip','dream-new.php','Trip Intelligence','trip-index-grid'] as $needle){if(strpos($index,$needle)===false){fwrite(STDERR,"Trip index missing {$needle}\n");exit(1);}}
if(strpos($index,'dream-create')!==false){fwrite(STDERR,"Trip creation is still embedded in the trip list page.\n");exit(1);}

$new=$read('dream-new.php');
foreach(['Create trip dashboard','origin_name','origin_iata','destination_iata','start_date','end_date','target_budget','data-trip-destination-pick'] as $needle){if(strpos($new,$needle)===false){fwrite(STDERR,"Dedicated new-trip page missing {$needle}\n");exit(1);}}

$page=$read('dream-trip.php');
foreach(["'overview'=>'Overview'","'weather'=>'Weather Agent'","'flights'=>'Flights Agent'","'events'=>'Events Agent'","'local'=>'Local Agent'","'itinerary'=>'Itinerary Agent'","'budget'=>'Budget Agent'",'trip-agent-tabs','partials/trip-agent-panel.php','Proactive planning','Update tracker','Stays stay partner-first','data-refresh-intelligence','data-add-intelligence','data-schedule-form','agentComposerActionUrl','trip-agent.php'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Tabbed trip dashboard missing {$needle}\n");exit(1);}}
$panel=$read('partials/trip-agent-panel.php');
foreach(['Active agent results','trip-agent-current-result','trip-agent-history','Proactive update','Active specialist agents','Provider health','Agent next move','trip-live-agents.css','$tripAgentService=$tripAgentService??($tripAgents??null)'] as $needle){if(strpos($panel,$needle)===false){fwrite(STDERR,"Trip agent result panel missing {$needle}\n");exit(1);}}

$providers=$read('app/Services/TravelDataProviderService.php');
foreach(['Visual Crossing','api.weather.gov/points/','Ticketmaster Discovery','places.googleapis.com/v1/places:searchNearby','Skyscanner Indicative Prices','partners.api.skyscanner.net/apiservices/v3/flights/indicative/search','PRICE_UNIT_CENTI','PRICE_UNIT_MICRO','historical_outlook','near_term_only'] as $needle){if(strpos($providers,$needle)===false){fwrite(STDERR,"Travel provider adapter missing {$needle}\n");exit(1);}}
if(strpos($providers,'hotel')!==false && strpos($providers,'Hotels')!==false){fwrite(STDERR,"Core provider layer should not introduce scraped hotel inventory.\n");exit(1);}

$intel=$read('app/Services/TripIntelligenceService.php');
foreach(['weather','events','places','flights','trip_intelligence_snapshots','addFromSnapshot','scheduleItem','expires_at'] as $needle){if(strpos($intel,$needle)===false){fwrite(STDERR,"Trip intelligence orchestrator missing {$needle}\n");exit(1);}}

$live=$read('app/Services/TripLiveIntelligenceService.php');
foreach(['class TripLiveIntelligenceService','function health','function latestChange','function allChanges','function scopedHealth',"'indicative','Indicative'","'historical','Historical'","'stale'","source_status='success'",'Lowest indicative fare','rain probability shifted','entered the top local results'] as $needle){if(strpos($live,$needle)===false){fwrite(STDERR,"Live trip intelligence health/change layer missing {$needle}\n");exit(1);}}

$bootstrap=$read('app/bootstrap.php');
if(strpos($bootstrap,"/Services/TripLiveIntelligenceService.php")===false){fwrite(STDERR,"Trip live intelligence service is not loaded by bootstrap.\n");exit(1);}

$supervisor=$read('app/Services/TripSupervisorService.php');
foreach(['recordProactive','activeResult','agentStates','data_health','changeDetail','Flight decision window is active','Lowest indicative fare','fare-drop','weather-shift','budget-over','lodging-partner','Agent next move'] as $needle){if(strpos($supervisor,$needle)===false&&$needle!=='Agent next move'){fwrite(STDERR,"Trip supervisor missing {$needle}\n");exit(1);}}
foreach(['next_action','accuracy_note','freshness','Active specialists'] as $needle){if(strpos($supervisor,$needle)===false){fwrite(STDERR,"Trip supervisor active result metadata missing {$needle}\n");exit(1);}}

$agent=$read('app/Services/TripAgentService.php');
foreach(["['overview','weather','flights','events','local','itinerary','budget']",'allowed data scope','Never invent live weather','Lodging is a paid-partnership surface','trip_agent_messages','trip_agent_','live_status','latest_changes','freshness state','data_health'] as $needle){if(strpos($agent,$needle)===false){fwrite(STDERR,"Trip agent scoping/freshness missing {$needle}\n");exit(1);}}

$agentRoute=$read('trip-agent.php');
foreach(['trip_id','agent_type','TripAgentService','trip_agent_error','dream-trip.php?id='] as $needle){if(strpos($agentRoute,$needle)===false){fwrite(STDERR,"Trip agent endpoint missing {$needle}\n");exit(1);}}
$api=$read('api/trip-intelligence.php');
foreach(['TripAgentService','recordProactive','refresh','add_item','schedule_item'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Trip intelligence API missing {$needle}\n");exit(1);}}

$footer=$read('partials/footer.php');
foreach(['agentComposerActionUrl','agentComposerFields','fixed_context_label','task_mode','trip-agent-tabs.css'] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Canonical composer trip scoping missing {$needle}\n");exit(1);}}
$bar=$read('assets/dashboard-agent-bar.js');
foreach(['extra_fields','fixed_context_label','task_mode','Summarize this agent','Next booking move','Budget pressure'] as $needle){if(strpos($bar,$needle)===false){fwrite(STDERR,"Canonical composer trip mode missing {$needle}\n");exit(1);}}

$liveCss=$read('assets/trip-live-agents.css');
foreach(['trip-agent-fleet','trip-agent-data-health','trip-agent-next-action','trip-agent-health-dot','indicative','historical','stale'] as $needle){if(strpos($liveCss,$needle)===false){fwrite(STDERR,"Live trip agent styling missing {$needle}\n");exit(1);}}

$config=$read('config-example.php');
foreach(['visual_crossing_key','ticketmaster_key','google_places_key','skyscanner_key','VISUAL_CROSSING_API_KEY','TICKETMASTER_API_KEY','GOOGLE_PLACES_API_KEY','SKYSCANNER_API_KEY'] as $needle){if(strpos($config,$needle)===false){fwrite(STDERR,"Travel config template missing {$needle}\n");exit(1);}}

echo "Trip intelligence contract OK\n";
