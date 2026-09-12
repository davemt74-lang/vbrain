<?php
declare(strict_types=1);

$root=dirname(__DIR__);$read=static fn(string $file):string=>file_get_contents($root.'/'.$file)?:'';
$files=['db/060_budget_aware_trip_planning.sql','app/Services/TripAffordabilityService.php','app/Services/TripAffordabilityAgentContextService.php','app/Services/TripAffordabilityInboxService.php','trip-affordability.php','partials/trip-affordability-summary.php','assets/trip-affordability.css','app/bootstrap.php','app/Services/BookingActionAgentContextService.php','app/Services/TripUnifiedInboxWorkerService.php','partials/trip-agent-panel.php','assets/trip-workspace-tabs.js'];
foreach($files as $file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Budget-Aware Trip Planning file: {$file}\n");exit(1);}

$m=$read('db/060_budget_aware_trip_planning.sql');
foreach(['CREATE TABLE trip_affordability_settings','CREATE TABLE trip_affordability_scenarios','CREATE TABLE trip_affordability_events','risk_tolerance','contingency_pct','disruption_reserve_pct','alert_overage_pct','include_learned_history','scenario_saved',"'app_version','1.52'"] as $needle)if(strpos($m,$needle)===false){fwrite(STDERR,"Affordability migration missing {$needle}\n");exit(1);}
foreach(['card_number','payment_method','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted','latitude','longitude'] as $bad)if(strpos($m,$bad)!==false){fwrite(STDERR,"Affordability schema crossed private/payment/location boundary: {$bad}\n");exit(1);}

$s=$read('app/Services/TripAffordabilityService.php');
foreach(['final class TripAffordabilityService','snapshot','saveSettings','saveScenario','deleteScenario','inboxProjection','safeAgentContext','fitSuggestions','valueScore','saved_provider_estimate','learned_history','expected_final','expected_remaining','headroom','risk_status','confidence','No hidden FX','does not guess exchange rates','max($b,$override)','Only the trip owner can change affordability assumptions or saved scenarios','planning estimates, not quotes',"'requires_action'=>true"] as $needle)if(strpos($s,$needle)===false){fwrite(STDERR,"Affordability service missing {$needle}\n");exit(1);}
foreach(['LiveTravelDataProviderService','->refresh(','TripBookingActionService','openHandoff','executeBookingComCancellation','prepareBookingComCancellation','confirmHandoff','navigator.geolocation','getCurrentPosition','watchPosition','exchange_rate','fx_rate','card_number','payment_method','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($s,$bad)!==false){fwrite(STDERR,"Affordability service crossed execution/location/FX/private boundary: {$bad}\n");exit(1);}

$agent=$read('app/Services/TripAffordabilityAgentContextService.php');
foreach(['AFFORDABILITY INTELLIGENCE','saved planning state only; no provider refresh','planning estimates, not quotes','Currencies are never converted implicitly','non-destructive ways to fit the target budget','canonical explicit approval flows'] as $needle)if(strpos($agent,$needle)===false){fwrite(STDERR,"Affordability agent context missing safety language: {$needle}\n");exit(1);}
foreach(['merchant_name','void_reason','source_booking_id','actual_spend','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($agent,$bad)!==false){fwrite(STDERR,"Affordability agent context exposes private implementation field: {$bad}\n");exit(1);}

$inbox=$read('app/Services/TripAffordabilityInboxService.php');foreach(['source_type','affordability','within_budget','Review affordability','source_fingerprint','resolved_at=COALESCE'] as $needle)if(strpos($inbox,$needle)===false){fwrite(STDERR,"Affordability Inbox projection missing {$needle}\n");exit(1);}
foreach(['payment_method','confirmation_code','merchant_name','actual_spend'] as $bad)if(strpos($inbox,$bad)!==false){fwrite(STDERR,"Affordability Inbox projection crossed private boundary: {$bad}\n");exit(1);}

$page=$read('trip-affordability.php');
foreach(['Budget-Aware Trip Planning','Can this trip fit the budget without ruining the trip?','No hidden FX and no financial execution','Expected final cost','Expected remaining','Make this trip fit my budget','Scenario lab','Value score','Forecast policy','verify_csrf()','name="_csrf"','save_settings','save_scenario','delete_scenario','Trip Economics'] as $needle)if(strpos($page,$needle)===false){fwrite(STDERR,"Affordability UI missing {$needle}\n");exit(1);}
foreach(['openHandoff','executeBookingComCancellation','prepareBookingComCancellation','navigator.geolocation','getCurrentPosition','watchPosition'] as $bad)if(strpos($page,$bad)!==false){fwrite(STDERR,"Affordability UI must not execute provider/location actions: {$bad}\n");exit(1);}

$boot=$read('app/bootstrap.php');foreach(['/Services/TripAffordabilityService.php','/Services/TripAffordabilityAgentContextService.php','/Services/TripAffordabilityInboxService.php'] as $needle)if(strpos($boot,$needle)===false){fwrite(STDERR,"Affordability bootstrap missing {$needle}\n");exit(1);}
$bookingAgent=$read('app/Services/BookingActionAgentContextService.php');foreach(['TripAffordabilityAgentContextService','$affordability->context($userId,3)'] as $needle)if(strpos($bookingAgent,$needle)===false){fwrite(STDERR,"Main agent missing affordability context: {$needle}\n");exit(1);}
$worker=$read('app/Services/TripUnifiedInboxWorkerService.php');foreach(['TripAffordabilityInboxService','$affordability->syncUser($uid)'] as $needle)if(strpos($worker,$needle)===false){fwrite(STDERR,"Travel worker missing affordability projection: {$needle}\n");exit(1);}
$panel=$read('partials/trip-agent-panel.php');foreach(['trip-affordability-summary.php'] as $needle)if(strpos($panel,$needle)===false){fwrite(STDERR,"Trip plan missing affordability summary: {$needle}\n");exit(1);}
$summary=$read('partials/trip-affordability-summary.php');foreach(['Affordability forecast','expected final cost','trip-affordability.php?id=','assets/trip-affordability.css'] as $needle)if(strpos($summary,$needle)===false){fwrite(STDERR,"Affordability summary missing {$needle}\n");exit(1);}
$tabs=$read('assets/trip-workspace-tabs.js');foreach(["['affordability','Affordability']",'trip-affordability.php?id=','Open affordability'] as $needle)if(strpos($tabs,$needle)===false){fwrite(STDERR,"Trip workspace missing Affordability entry: {$needle}\n");exit(1);}
$css=$read('assets/trip-affordability.css');foreach(['vb-aff-scoreboard','vb-aff-grid','vb-aff-table','vb-aff-fit-list','vb-aff-scenarios','vb-affordability-summary','@media(max-width:700px)'] as $needle)if(strpos($css,$needle)===false){fwrite(STDERR,"Affordability styling missing {$needle}\n");exit(1);}

echo "Budget-Aware Trip Planning + Affordability Intelligence contract OK\n";
