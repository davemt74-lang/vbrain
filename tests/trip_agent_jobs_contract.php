<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/037_trip_agent_jobs.sql',
    'app/Services/TripAgentJobService.php',
    'app/Services/TripAgentService.php',
    'api/trip-agent-jobs.php',
    'bin/run-trip-agent-jobs.php',
    'api/brain-activity.php',
    'assets/brain-activity.js',
    'assets/brain-activity.css',
    'app/bootstrap.php',
    'partials/footer.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing live agent job file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/037_trip_agent_jobs.sql');
foreach(['CREATE TABLE trip_agent_jobs',"ENUM('overview','weather','flights','events','local','itinerary','budget')",'uq_trip_agent_job_active','idx_trip_agent_job_queue','idx_trip_agent_job_heartbeat','active_key','worker_token','attempt_count',"'app_version','1.29'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Agent job migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TripAgentJobService.php');
foreach(['class TripAgentJobService','MAX_ACTIVE_JOBS=20','STALE_MINUTES=20','MAX_ATTEMPTS=2','function enqueue','function enqueueAll','function cancel','function activityStates','function runDue','function recoverStale','worker_token','active_key=NULL','TripAgentService','trip-agent-job:','NotificationService','INSERT INTO trip_agent_jobs',"status='running'","status='completed'","status='failed'",'DomainException'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Agent job service missing {$needle}\n");exit(1);}}

$agent=$read('app/Services/TripAgentService.php');
foreach(['?string $idempotencyKey=null','byFingerprint','INSERT IGNORE','$assistantFingerprint',"'reused'=>true",'job_key'] as $needle){if(strpos($agent,$needle)===false){fwrite(STDERR,"Trip agent idempotency missing {$needle}\n");exit(1);}}

$api=$read('api/trip-agent-jobs.php');
foreach(['require_auth','verify_csrf','Cache-Control','enqueue','run_all','cancel','http_response_code(409)','http_response_code(503)','temporarily unavailable'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Agent job API missing {$needle}\n");exit(1);}}

$runner=$read('bin/run-trip-agent-jobs.php');
foreach(["PHP_SAPI!=='cli'",'runDue','--limit=','Run System Upgrade'] as $needle){if(strpos($runner,$needle)===false){fwrite(STDERR,"Agent job worker missing {$needle}\n");exit(1);}}

$brainApi=$read('api/brain-activity.php');
foreach(['vb_apply_agent_jobs_to_activity','TripAgentJobService','jobs_available','active_agent_jobs','running_jobs','queued_jobs','Agents Working','Agents Queued'] as $needle){if(strpos($brainApi,$needle)===false){fwrite(STDERR,"Brain Activity live-job integration missing {$needle}\n");exit(1);}}

$js=$read('assets/brain-activity.js');
foreach(['job_api','data-vb-job-action','run_all','Run all agents','Working ','Queued · cancel','active_agent_jobs','5000:60000','setTimeout','visibilitychange'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Agent EEG queue UI missing {$needle}\n");exit(1);}}
if(strpos($js,'Math.random')!==false){fwrite(STDERR,"Live Agent EEG must not use random activity.\n");exit(1);}

$css=$read('assets/brain-activity.css');foreach(['vb-eeg-job-button','vb-run-all-agents','data-job-state="running"','vbAgentPulse','vb-eeg-job-message'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Live Agent EEG styling missing {$needle}\n");exit(1);}}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripAgentJobService.php")===false){fwrite(STDERR,"TripAgentJobService is not loaded by bootstrap.\n");exit(1);}
$footer=$read('partials/footer.php');foreach(['api/trip-agent-jobs.php',"'job_api'","'csrf'=>csrf_token()"] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Live Agent EEG footer config missing {$needle}\n");exit(1);}}

echo "Trip Agent Jobs contract OK\n";
