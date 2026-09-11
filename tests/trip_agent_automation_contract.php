<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/039_trip_agent_automation_triggers.sql',
    'app/Services/TripAgentAutomationService.php',
    'app/Services/TripAgentBatchService.php',
    'app/Services/TripAgentJobService.php',
    'bin/run-trip-agent-jobs.php',
    'api/brain-activity.php',
    'assets/brain-activity.js',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing proactive trip-agent automation file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/039_trip_agent_automation_triggers.sql');
foreach(['CREATE TABLE trip_agent_automation_triggers',"ENUM('pending','dispatching','deferred','dispatched','failed','suppressed')",'uq_trip_agent_automation_event','idx_trip_agent_automation_queue','travel_watch_event_id','batch_id','fk_trip_agent_automation_event','fk_trip_agent_automation_batch',"ENUM('single','all','automation')",'trip_agent_automation_started_at',"'app_version','1.31'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Automation migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TripAgentAutomationService.php');
foreach(['class TripAgentAutomationService','MAX_DAILY_BATCHES=6','MAX_ATTEMPTS=5','DEBOUNCE_SECONDS=45','function runDue','function activityState','function syncWatchEvents','function suppressInactive','function recoverStaleClaims','function claimNextGroup','function dispatchClaim','function dailyBatchCount','travel_watch_events','travel_watches',"w.target_type='trip'",'w.is_active=1',"e.notified_at IS NOT NULL",'trip_agent_automation_started_at','DATE_ADD(NOW(),INTERVAL $delay SECOND)','LEFT JOIN trip_agent_automation_triggers existing','existing.id IS NULL','ORDER BY e.id ASC LIMIT $limit','enqueueAgentSet',"'automation'",'$agentList[]=\'overview\'','hasActiveTripJobs','Daily proactive-agent limit','COUNT(DISTINCT batch_id)','Watch is no longer active','notifyAutomationFailure'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Automation service missing {$needle}\n");exit(1);}}
if(strpos($service,'Math.random')!==false){fwrite(STDERR,"Server automation must never use random scheduling.\n");exit(1);}

$batch=$read('app/Services/TripAgentBatchService.php');
foreach(["['single','all','automation']",'requiredTypes($agents,$scope)','$scope===\'automation\'','$agent!==\'overview\''] as $needle){if(strpos($batch,$needle)===false){fwrite(STDERR,"Automation batch cost scoping missing {$needle}\n");exit(1);}}

$jobs=$read('app/Services/TripAgentJobService.php');
foreach(['function enqueueAgentSet','function hasActiveTripJobs','SELECT id FROM users WHERE id=? FOR UPDATE','SELECT id FROM dream_trips WHERE id=? AND user_id=? FOR UPDATE',"'automation'",'batchScope','trip_agent_automation','watch follow-up','agentType!==\'overview\''] as $needle){if(strpos($jobs,$needle)===false){fwrite(STDERR,"Automation-safe job orchestration missing {$needle}\n");exit(1);}}

$runner=$read('bin/run-trip-agent-jobs.php');
foreach(['TripAgentAutomationService','automationResult','automation->runDue','service->runDue','automationResult[\'failed\']'] as $needle){if(strpos($runner,$needle)===false){fwrite(STDERR,"Agent worker automation integration missing {$needle}\n");exit(1);}}

$brain=$read('api/brain-activity.php');
foreach(['vb_apply_agent_automation_to_activity','TripAgentAutomationService','pending_automation_triggers','dispatching_automation_triggers','watch_triggered','Dispatching Proactive Agents','Watch Change Waiting for Agents'] as $needle){if(strpos($brain,$needle)===false){fwrite(STDERR,"Brain Activity proactive automation state missing {$needle}\n");exit(1);}}

$js=$read('assets/brain-activity.js');
foreach(['Starting proactive agents','Watch change queued','pending_automation_triggers','dispatching_automation_triggers','A watch change is waking the relevant specialist agents and Overview','fastPoll=agentJobs>0||dispatching>0','fastPoll?5000:60000'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Agent EEG proactive automation UI missing {$needle}\n");exit(1);}}
if(strpos($js,'activeJobs>0?5000:60000')!==false){fwrite(STDERR,"Deferred proactive triggers must not force 5-second polling for hours.\n");exit(1);}
if(strpos($js,'Math.random')!==false){fwrite(STDERR,"Agent EEG automation must reflect real states, not random animation.\n");exit(1);}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripAgentAutomationService.php")===false){fwrite(STDERR,"TripAgentAutomationService is not loaded by bootstrap.\n");exit(1);}

echo "Trip Agent Automation contract OK\n";