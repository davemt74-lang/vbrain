<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/040_trip_agent_action_queue.sql',
    'app/Services/TripAgentActionService.php',
    'trip-action.php',
    'partials/trip-action-queue.php',
    'partials/trip-agent-panel.php',
    'assets/trip-live-agents.css',
    'bin/run-trip-agent-jobs.php',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Trip Agent action queue file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/040_trip_agent_action_queue.sql');
foreach(['CREATE TABLE trip_agent_actions',"ENUM('open','accepted','dismissed','completed','superseded')",'uq_trip_agent_action_source','idx_trip_agent_action_queue','fk_trip_agent_action_user','fk_trip_agent_action_trip','fk_trip_agent_action_batch','actions_synced_at','idx_trip_agent_batch_actions_sync','UPDATE trip_agent_batches b',"j.agent_type='overview'","j.status IN ('queued','running')","'app_version','1.32'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Action queue migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TripAgentActionService.php');
foreach(['class TripAgentActionService','function syncCompletedOverviewBatches','function syncFromDashboard','function queueForTrip','function updateStatus','function counts','TripSupervisorService','dashboardForJob','actions_synced_at IS NULL',"j.agent_type='overview'","j.status='completed'",'sourceKey=$key',"status=IF(status='superseded','open',status)","status='open' AND source_key NOT IN","status IN ('open','accepted')","FIELD(status,'accepted','open')",'JSON_INVALID_UTF8_SUBSTITUTE'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Action queue service missing {$needle}\n");exit(1);}}
foreach(["status='dismissed'","status='completed'","status='accepted'"] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Action queue lifecycle missing {$needle}\n");exit(1);}}
if(strpos($service,"status=IF(status IN ('dismissed','completed')")!==false){fwrite(STDERR,"Dismissed/completed decisions must not be reopened automatically.\n");exit(1);}

$endpoint=$read('trip-action.php');
foreach(['require_auth','REQUEST_METHOD','verify_csrf()','TripAgentActionService','updateStatus','trip_id','action_id','return_tab','#next-moves'] as $needle){if(strpos($endpoint,$needle)===false){fwrite(STDERR,"Trip action endpoint missing {$needle}\n");exit(1);}}

$partial=$read('partials/trip-action-queue.php');
foreach(['id="next-moves"','Decision queue','Next Moves','syncFromDashboard','csrf_token()','trip-action.php','I’m on it','Dismiss','Done','Release','Apply migration 040'] as $needle){if(strpos($partial,$needle)===false){fwrite(STDERR,"Next Moves UI missing {$needle}\n");exit(1);}}
$panel=$read('partials/trip-agent-panel.php');if(strpos($panel,'$activeAgent===\'overview\'')===false||strpos($panel,'trip-action-queue.php')===false){fwrite(STDERR,"Next Moves must mount only on Overview.\n");exit(1);}

$css=$read('assets/trip-live-agents.css');foreach(['trip-next-moves','trip-action-counts','trip-action-list','trip-action-card','data-status="accepted"','trip-action-controls'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Next Moves styling missing {$needle}\n");exit(1);}}

$worker=$read('bin/run-trip-agent-jobs.php');foreach(['TripAgentActionService','syncCompletedOverviewBatches',"'actions'=>"] as $needle){if(strpos($worker,$needle)===false){fwrite(STDERR,"Background action persistence missing {$needle}\n");exit(1);}}
$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripAgentActionService.php")===false){fwrite(STDERR,"TripAgentActionService is not loaded by bootstrap.\n");exit(1);}

echo "Trip Agent Actions contract OK\n";