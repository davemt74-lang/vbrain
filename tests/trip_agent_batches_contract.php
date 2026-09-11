<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/038_trip_agent_intelligence_batches.sql',
    'app/Services/TripAgentBatchService.php',
    'app/Services/TripAgentJobService.php',
    'app/Services/TripAgentService.php',
    'api/brain-activity.php',
    'assets/brain-activity.js',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing coherent agent batch file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/038_trip_agent_intelligence_batches.sql');
foreach(['CREATE TABLE trip_agent_batches',"ENUM('queued','refreshing','ready','failed','cancelled')",'required_types_json','refreshed_types_json','snapshot_ids_json','context_json','worker_token','ADD COLUMN batch_id','fk_trip_agent_job_batch',"'app_version','1.30'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Batch migration missing {$needle}\n");exit(1);}}

$batch=$read('app/Services/TripAgentBatchService.php');
foreach(['class TripAgentBatchService','function create','function prepareDue','function dashboardForJob','function cancelIfInactive','function recoverStale','requiredTypes','TripIntelligenceService','TripLiveIntelligenceService','TripSupervisorService','array_intersect($required,$stale)','EXISTS (SELECT 1 FROM trip_agent_jobs','snapshot_ids','prepared_at','supervisor','specialist_results','MAX_ATTEMPTS=2','STALE_MINUTES=20','JSON_INVALID_UTF8_SUBSTITUTE','failQueuedJobs','notifyFailure'] as $needle){if(strpos($batch,$needle)===false){fwrite(STDERR,"Batch service missing {$needle}\n");exit(1);}}

$jobs=$read('app/Services/TripAgentJobService.php');
foreach(['TripAgentBatchService','batch_id','Preparing shared trip intelligence','activeTripCount','FOR UPDATE','prepareDue','dashboardForJob','cancelIfInactive','Reading coherent trip intelligence',"agent_type<>'overview'",'NOT EXISTS','specialist','defaultRequest'] as $needle){if(strpos($jobs,$needle)===false){fwrite(STDERR,"Job/batch orchestration missing {$needle}\n");exit(1);}}
if(substr_count($jobs,'prepareDue(')!==1){fwrite(STDERR,"Agent worker must coordinate batch preparation once per run path.\n");exit(1);}

$agent=$read('app/Services/TripAgentService.php');
foreach(['?array $dashboardOverride=null','?int $batchId=null','intelligence_batch_id','analysis_batch','immutable shared intelligence batch','specialist_results',"\$batch['supervisor']",'snapshot_ids','JSON_INVALID_UTF8_SUBSTITUTE'] as $needle){if(strpos($agent,$needle)===false){fwrite(STDERR,"Trip agent frozen-context support missing {$needle}\n");exit(1);}}

$brain=$read('api/brain-activity.php');
foreach(['batch_status','batch_id','preparing','Refreshing Trip Intelligence','coherent provider snapshot','shared trip-intelligence snapshot'] as $needle){if(strpos($brain,$needle)===false){fwrite(STDERR,"EEG batch-state integration missing {$needle}\n");exit(1);}}

$js=$read('assets/brain-activity.js');
foreach(['Preparing shared data','shared snapshot #','Shared intelligence batch active','run-all agents share one provider snapshot','Starting shared batch','refresh stale provider data once','Agents active'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"EEG coherent-batch UI missing {$needle}\n");exit(1);}}
if(strpos($js,'Math.random')!==false){fwrite(STDERR,"Coherent Agent EEG must not use random activity.\n");exit(1);}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripAgentBatchService.php")===false){fwrite(STDERR,"TripAgentBatchService is not loaded by bootstrap.\n");exit(1);}

echo "Trip Agent Batches contract OK\n";
