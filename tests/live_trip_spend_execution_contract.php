<?php
declare(strict_types=1);

$root=dirname(__DIR__);$read=static fn(string $file):string=>file_get_contents($root.'/'.$file)?:'';
$files=['db/061_live_trip_spend_execution.sql','app/Services/TripSpendExecutionService.php','app/Services/TripSpendExecutionAgentContextService.php','app/Services/TripSpendInboxService.php','trip-spend.php','partials/trip-spend-summary.php','partials/travel-mode-spend-panel.php','assets/trip-spend.css','app/Services/BookingActionAgentContextService.php','app/Services/TripUnifiedInboxWorkerService.php','partials/trip-agent-panel.php','partials/footer.php','assets/trip-workspace-tabs.js'];
foreach($files as $file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Live Trip Spend file: {$file}\n");exit(1);}

$m=$read('db/061_live_trip_spend_execution.sql');
foreach(['CREATE TABLE trip_spend_execution_settings','CREATE TABLE trip_spend_entry_meta','CREATE TABLE trip_spend_events','alert_spend_pct','daily_allowance_override','receipt_status','receipt_reference','reconciliation_status','shared_scope','attributed_user_id',"'app_version','1.53'"] as $needle)if(strpos($m,$needle)===false){fwrite(STDERR,"Live spend migration missing {$needle}\n");exit(1);}
foreach(['card_number','payment_method','access_token_encrypted','refresh_token_encrypted','provider_state_encrypted','confirmation_code','bank_account','routing_number'] as $bad)if(strpos($m,$bad)!==false){fwrite(STDERR,"Live spend schema crossed payment/provider-secret boundary: {$bad}\n");exit(1);}

$s=$read('app/Services/TripSpendExecutionService.php');
foreach(['final class TripSpendExecutionService','snapshot','saveSettings','captureExpense','updateEntryMeta','voidExpense','safeAgentContext','inboxProjection','remaining_daily_allowance','planning_expected_final','pace_status','recoverySuggestions','sharedAttributionCounts','Only the trip owner can manage live spend','does not guess exchange rates','not bank settlement','Planning expected final remains a forecast'] as $needle)if(strpos($s,$needle)===false){fwrite(STDERR,"Live spend service missing {$needle}\n");exit(1);}
foreach(['LiveTravelDataProviderService','->refresh(','openHandoff','executeBookingComCancellation','prepareBookingComCancellation','navigator.geolocation','getCurrentPosition','watchPosition','exchange_rate','fx_rate','card_number','payment_method','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($s,$bad)!==false){fwrite(STDERR,"Live spend service crossed execution/location/FX/payment boundary: {$bad}\n");exit(1);}

$agent=$read('app/Services/TripSpendExecutionAgentContextService.php');
foreach(['LIVE TRIP SPEND + BUDGET EXECUTION','owner-only aggregate ledger context','not bank settlement','Merchant names, notes, receipt references, booking IDs and traveler attribution identities are excluded','Currencies are never converted implicitly','canonical explicit approval flows'] as $needle)if(strpos($agent,$needle)===false){fwrite(STDERR,"Live spend agent context missing safety language: {$needle}\n");exit(1);}
foreach(['merchant_name','receipt_reference','attributed_user_id','source_booking_id','confirmation_code'] as $bad)if(strpos($agent,$bad)!==false){fwrite(STDERR,"Live spend agent context exposes private implementation field: {$bad}\n");exit(1);}

$inbox=$read('app/Services/TripSpendInboxService.php');foreach(['spend_execution','within_pace','Review live spend','source_fingerprint','resolved_at=COALESCE',"spend-execution-within-pace:"] as $needle)if(strpos($inbox,$needle)===false){fwrite(STDERR,"Live spend Inbox projection missing {$needle}\n");exit(1);}
foreach(['merchant_name','receipt_reference','attributed_user_id','source_booking_id','payment_method','confirmation_code'] as $bad)if(strpos($inbox,$bad)!==false){fwrite(STDERR,"Live spend Inbox crossed private boundary: {$bad}\n");exit(1);}

$page=$read('trip-spend.php');foreach(['Live Trip Spend · Budget Execution','Stay on budget while the trip is actually happening.','Recorded actual','Daily allowance left','Planning expected final','Actual vs category plan','Private spend ledger','Budget recovery','capture_expense','save_settings','update_meta','void_expense','verify_csrf()','name="_csrf"','Receipt reference','Trip attribution','Forecast, not settled spend'] as $needle)if(strpos($page,$needle)===false){fwrite(STDERR,"Live spend UI missing {$needle}\n");exit(1);}
foreach(['openHandoff','executeBookingComCancellation','navigator.geolocation','getCurrentPosition','watchPosition'] as $bad)if(strpos($page,$bad)!==false){fwrite(STDERR,"Live spend UI must not execute provider/location actions: {$bad}\n");exit(1);}

$booking=$read('app/Services/BookingActionAgentContextService.php');foreach(['TripSpendExecutionAgentContextService','$spend->context($userId,3)'] as $needle)if(strpos($booking,$needle)===false){fwrite(STDERR,"Main agent missing live spend context: {$needle}\n");exit(1);}
$worker=$read('app/Services/TripUnifiedInboxWorkerService.php');foreach(['TripSpendInboxService','$spend->syncUser($uid)'] as $needle)if(strpos($worker,$needle)===false){fwrite(STDERR,"Travel worker missing live spend Inbox sync: {$needle}\n");exit(1);}
$panel=$read('partials/trip-agent-panel.php');if(strpos($panel,'trip-spend-summary.php')===false){fwrite(STDERR,"Planning Hub missing live spend summary.\n");exit(1);}
$travel=$read('partials/travel-mode-spend-panel.php');foreach(['Travel Mode · Budget Execution','Nothing in this panel is written to the offline itinerary copy','Review live spend'] as $needle)if(strpos($travel,$needle)===false){fwrite(STDERR,"Travel Mode spend panel missing {$needle}\n");exit(1);}
$footer=$read('partials/footer.php');if(strpos($footer,'travel-mode-spend-panel.php')===false){fwrite(STDERR,"Travel Mode footer does not load spend panel.\n");exit(1);}
$tabs=$read('assets/trip-workspace-tabs.js');foreach(["['spend','Live Spend']",'trip-spend.php?id=','Open live spend'] as $needle)if(strpos($tabs,$needle)===false){fwrite(STDERR,"Trip workspace missing Live Spend entry: {$needle}\n");exit(1);}
$css=$read('assets/trip-spend.css');foreach(['vb-spend-scoreboard','vb-spend-grid','vb-spend-table','vb-spend-ledger','vb-spend-summary','@media(max-width:700px)'] as $needle)if(strpos($css,$needle)===false){fwrite(STDERR,"Live spend styling missing {$needle}\n");exit(1);}

echo "Live Trip Spend + Budget Execution contract OK\n";
