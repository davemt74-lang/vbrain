<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/051_autonomous_travel_operations.sql',
    'app/Services/AutonomousTravelOperationsService.php',
    'app/Services/TripAgentExecutionService.php',
    'app/Services/LiveTravelAgentContextService.php',
    'autonomous-trip.php',
    'assets/autonomous-travel.css',
    'partials/proactive-trip-panel.php',
    'bin/run-trip-agent-jobs.php',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Autonomous Travel Operations file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/051_autonomous_travel_operations.sql');
foreach(['CREATE TABLE trip_autonomy_controls','CREATE TABLE trip_autonomy_decisions','enabled TINYINT(1) NOT NULL DEFAULT 0',"ENUM('observe','research','planning')",'max_auto_starts_per_day','max_auto_applies_per_day','per_action_estimated_limit','daily_estimated_limit','pause_on_verification_pending','authorization_source',"ENUM('user','autonomy_policy')",'policy_authorized',"'app_version','1.43'"] as $needle){if(strpos($m,$needle)===false){fwrite(STDERR,"Autonomy migration missing {$needle}\n");exit(1);}}
if(strpos($m,"DEFAULT 1")!==false&&strpos($m,'enabled TINYINT(1) NOT NULL DEFAULT 0')===false){fwrite(STDERR,"Autonomy must remain disabled by default.\n");exit(1);}

$s=$read('app/Services/AutonomousTravelOperationsService.php');
foreach(['class AutonomousTravelOperationsService','function settings','function saveSettings','function status','function startEligible','function applyEligible','function agentContext','function fallback','SAFE_PROPOSAL_TYPES','planning_task','itinerary_item','SAFE_ITEM_TYPES','idea','food','activity','experience','SAFE_SOURCES','proactive_travel','trip_supervisor','verification_pending','max_auto_starts_per_day','max_auto_applies_per_day','per_action_estimated_limit','daily_estimated_limit','automatic retry is disabled','GET_LOCK','RELEASE_LOCK','withTripLock','auditPayload','Standing autonomy policy','non-transactional planning','never approves provider checkout'] as $needle){if(stripos($s,$needle)===false){fwrite(STDERR,"Autonomy policy service missing {$needle}\n");exit(1);}}
foreach(['TripBookingActionService','executeApproved','approveIntent','executeIntent','cancelAccommodation','providerCancel','payment-card','card_number'] as $forbidden){if(stripos($s,$forbidden)!==false){fwrite(STDERR,"Autonomy policy must not call transaction/provider execution path: {$forbidden}\n");exit(1);}}
if(strpos($s,"c.enabled=1")===false||strpos($s,"c.autonomy_mode='planning'")===false){fwrite(STDERR,"Autonomy worker queries must require explicit enabled planning policy.\n");exit(1);}
if(strpos($s,"source_url']??''")===false||strpos($s,"requires_external_confirmation")===false||strpos($s,"source_provider")===false||strpos($s,"source_external_id")===false){fwrite(STDERR,"Autonomy apply must verify canonical, non-external proposal provenance.\n");exit(1);}

$execution=$read('app/Services/TripAgentExecutionService.php');
foreach(['function applyAutonomous','AUTONOMY_TYPES','AUTONOMY_ITEMS',"authorization_source='autonomy_policy'",'approved_at=NULL','policy_authorized','autonomyDecision','trip_autonomy_decisions','requires_external_confirmation','External action URLs are not eligible','Only Vacation Brain-generated planning proposals','Proposal provenance is not the canonical Trip Action path','Flight, lodging, merchandise, and unknown item types cannot be auto-applied','saved standing-policy evaluation'] as $needle){if(strpos($execution,$needle)===false){fwrite(STDERR,"Canonical execution autonomy hardening missing {$needle}\n");exit(1);}}
if(strpos($execution,"authorization_source='user'")===false){fwrite(STDERR,"Manual approvals must remain distinguishable from standing-policy authorization.\n");exit(1);}
if(strpos($execution,'applyAutonomous')===false||strpos($execution,'applyProposal')===false){fwrite(STDERR,"Autonomy must reuse the canonical non-transactional apply path.\n");exit(1);}

$page=$read('autonomous-trip.php');
foreach(['Autonomous Travel Operations','standing policy is trip-specific and off by default','Observe only','Research autopilot','Planning autopilot','Enable for this trip','Estimated value limit / item','Estimated value limit / day','Hard boundary — not configurable','never authorizes provider checkout','no charge authorized','DreamService'] as $needle){if(stripos($page,$needle)===false){fwrite(STDERR,"Autonomy UI missing {$needle}\n");exit(1);}}
foreach(['require_auth','verify_csrf','saveSettings'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Autonomy UI boundary missing {$needle}\n");exit(1);}}

$worker=$read('bin/run-trip-agent-jobs.php');
foreach(['AutonomousTravelOperationsService','startEligible','applyEligible','autonomy_start','autonomy_apply','autonomy_start_after_sync'] as $needle){if(strpos($worker,$needle)===false){fwrite(STDERR,"Existing Trip Agent worker missing autonomy integration {$needle}\n");exit(1);}}
if(file_exists($root.'/bin/run-autonomous-travel.php')){fwrite(STDERR,"Autonomy must reuse the existing Trip Agent worker; do not create a parallel cron.\n");exit(1);}

$live=$read('app/Services/LiveTravelAgentContextService.php');
foreach(['AutonomousTravelOperationsService','agentContext($userId,$tripId)','fallback($userId,$tripId)','autonomous-operations ledgers','never includes booking confirmation codes'] as $needle){if(stripos($live,$needle)===false){fwrite(STDERR,"Main agent autonomy grounding missing {$needle}\n");exit(1);}}
if(strpos($live,'applyEligible(')!==false||strpos($live,'startEligible(')!==false){fwrite(STDERR,"Main chat context must remain read-only and never run autonomy.\n");exit(1);}

$panel=$read('partials/proactive-trip-panel.php');if(strpos($panel,'autonomous-trip.php?id=')===false||strpos($panel,'Autonomy')===false){fwrite(STDERR,"Trip/proactive surface needs an Autonomy entry point.\n");exit(1);}
$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/AutonomousTravelOperationsService.php")===false){fwrite(STDERR,"AutonomousTravelOperationsService is not bootstrapped.\n");exit(1);}
$css=$read('assets/autonomous-travel.css');foreach(['vb-autonomy-summary','vb-autonomy-layout','vb-autonomy-modes','vb-autonomy-boundary','vb-autonomy-ledger'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Autonomy styling missing {$needle}\n");exit(1);}}

echo "Autonomous Travel Operations contract OK\n";
