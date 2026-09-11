<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/047_proactive_vacation_brain.sql',
    'app/Services/ProactiveTravelService.php',
    'proactive-trip.php',
    'proactive-settings.php',
    'partials/proactive-trip-panel.php',
    'partials/proactive-dashboard-summary.php',
    'assets/proactive-travel.css',
    'app/Services/LiveTravelAgentContextService.php',
    'app/bootstrap.php',
    'partials/footer.php',
    'bin/run-travel-watches.php',
    'notification-settings.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Proactive Vacation Brain file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/047_proactive_vacation_brain.sql');
foreach(['CREATE TABLE proactive_travel_preferences','CREATE TABLE trip_proactive_issues','CREATE TABLE trip_proactive_briefings','auto_research','morning_briefing','minimum_severity','quiet_start','source_state','confidence','auto_research_started_at',"'app_version','1.39'"] as $needle){if(strpos($m,$needle)===false){fwrite(STDERR,"Proactive migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/ProactiveTravelService.php');
foreach(['class ProactiveTravelService','function runUpcoming','function assessTrip','function dashboardSummary','function dismissIssue','function agentContext','PROACTIVE TRIP STATE','readinessIssues','flightIssues','weatherIssues','itineraryIssues','budgetIssues','freshnessIssues','opportunityIssues','TripAgentExecutionService','acceptAndStart','trip_agent_actions','trip_proactive_issues','trip_proactive_briefings','morning_tolerance','TravelerMemoryGraphService','source_state','confidence','approval_required_for_changes','no provider refresh'] as $needle){if(stripos($service,$needle)===false){fwrite(STDERR,"Proactive engine missing {$needle}\n");exit(1);}}
foreach(['confirmation_code','private_notes','payment_details','card_number'] as $forbidden){if(strpos($service,$forbidden)!==false){fwrite(STDERR,"Proactive engine must not read sensitive field identifier {$forbidden}.\n");exit(1);}}
if(strpos($service,"'awaiting_approval'")!==false || strpos($service,'approveAndApply')!==false){fwrite(STDERR,"Proactive engine must not auto-approve or apply proposals.\n");exit(1);}

$runner=$read('bin/run-travel-watches.php');
foreach(['TravelWatchService','TripTravelOperationsService','ProactiveTravelService','runUpcoming','proactive','PHP_SAPI'] as $needle){if(strpos($runner,$needle)===false){fwrite(STDERR,"Existing travel-watch worker missing proactive orchestration {$needle}\n");exit(1);}}
if(is_file($root.'/bin/run-proactive-travel.php')){fwrite(STDERR,"Proactive Vacation Brain must use the existing travel-watch worker; do not add a second proactive worker.\n");exit(1);}

$settings=$read('proactive-settings.php');
foreach(['require_auth','verify_csrf','proactive_enabled','urgent_alerts','opportunity_alerts','auto_research','morning_briefing','minimum_severity','quiet_start','quiet_end','still requires explicit approval','private Trip Memory notes'] as $needle){if(stripos($settings,$needle)===false){fwrite(STDERR,"Proactive settings missing {$needle}\n");exit(1);}}

$page=$read('proactive-trip.php');
foreach(['require_auth','verify_csrf','ProactiveTravelService','risk_score','source_state','confidence','Open Next Move','Agent research started','Morning & travel-day briefings','No external provider refresh was triggered',"'worker'"] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Proactive trip page missing {$needle}\n");exit(1);}}

$panel=$read('partials/proactive-trip-panel.php');foreach(['ProactiveTravelService','risk_score','Open proactive trip view',"'worker'"] as $needle){if(strpos($panel,$needle)===false){fwrite(STDERR,"Proactive trip panel missing {$needle}\n");exit(1);}}
$dashboard=$read('partials/proactive-dashboard-summary.php');foreach(['dashboardSummary','Trips that need your attention','source_state','confidence'] as $needle){if(strpos($dashboard,$needle)===false){fwrite(STDERR,"Proactive dashboard summary missing {$needle}\n");exit(1);}}

$context=$read('app/Services/LiveTravelAgentContextService.php');foreach(['ProactiveTravelService','agentContext','no provider refresh','payment data','private Trip Memory notes'] as $needle){if(stripos($context,$needle)===false){fwrite(STDERR,"Main Vacation Brain proactive grounding missing {$needle}\n");exit(1);}}
if(strpos($context,'confirmation_code')!==false||strpos($context,'private_notes')!==false){fwrite(STDERR,"Main-agent proactive context must not query sensitive booking/memory fields.\n");exit(1);}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/ProactiveTravelService.php")===false){fwrite(STDERR,"ProactiveTravelService is not loaded by bootstrap.\n");exit(1);}
$footer=$read('partials/footer.php');foreach(['proactive-dashboard-summary.php','proactive-trip-panel.php','assets/proactive-travel.css'] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Global proactive UI integration missing {$needle}\n");exit(1);}}
$notification=$read('notification-settings.php');if(strpos($notification,'proactive-settings.php')===false){fwrite(STDERR,"Notification settings must link to proactive travel controls.\n");exit(1);}
$css=$read('assets/proactive-travel.css');foreach(['vb-proactive-hero','vb-proactive-issue','vb-proactive-summary','vb-proactive-panel'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Proactive styling missing {$needle}\n");exit(1);}}

echo "Proactive Vacation Brain contract OK\n";
