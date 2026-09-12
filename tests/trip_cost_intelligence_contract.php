<?php
declare(strict_types=1);

$root=dirname(__DIR__);$read=static fn(string $file):string=>file_get_contents($root.'/'.$file)?:'';
$files=['db/059_trip_cost_intelligence.sql','app/Services/TripCostIntelligenceService.php','app/Services/TripCostIntelligenceAgentContextService.php','trip-cost.php','assets/trip-cost-intelligence.css','app/bootstrap.php','app/Services/BookingActionAgentContextService.php','assets/trip-workspace-tabs.js'];
foreach($files as $file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Trip Cost Intelligence file: {$file}\n");exit(1);}

$m=$read('db/059_trip_cost_intelligence.sql');
foreach(['CREATE TABLE trip_cost_category_plans','CREATE TABLE trip_cost_entries','CREATE TABLE trip_cost_events','cost_category','voided_at','source_booking_id',"'app_version','1.51'"] as $needle)if(strpos($m,$needle)===false){fwrite(STDERR,"Trip cost migration missing {$needle}\n");exit(1);}
foreach(['card_number','payment_method','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($m,$bad)!==false){fwrite(STDERR,"Trip cost schema crossed private/payment boundary: {$bad}\n");exit(1);}

$s=$read('app/Services/TripCostIntelligenceService.php');
foreach(['final class TripCostIntelligenceService','saveCategoryPlan','addExpense','voidExpense','historyModel','safeAgentContext','structured_gross_cash_outflow','net_cash_cost','reported_actual','comparison_basis_source','structured_coverage_pct','credits_received','cash_recovered','learning_enabled=1','Each currency is shown independently','Provider credits remain non-cash','Only the trip owner can manage actual expenses and category budgets','Expense amount must be greater than zero'] as $needle)if(strpos($s,$needle)===false){fwrite(STDERR,"Trip cost service missing {$needle}\n");exit(1);}
foreach(['LiveTravelDataProviderService','TripBookingActionService','openHandoff','executeBookingComCancellation','prepareBookingComCancellation','confirmHandoff','navigator.geolocation','getCurrentPosition','watchPosition','exchange_rate','fx_rate','card_number','payment_method','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($s,$bad)!==false){fwrite(STDERR,"Trip cost service crossed execution/location/FX/private boundary: {$bad}\n");exit(1);}

$agent=$read('app/Services/TripCostIntelligenceAgentContextService.php');foreach(['TRIP COST INTELLIGENCE','saved aggregate ledger only','Each currency is independent','Provider credits are non-cash','Traveler-reported actual spend and private manual-expense details are owner-only','existing explicit transaction approval path'] as $needle)if(strpos($agent,$needle)===false){fwrite(STDERR,"Trip cost agent context missing safety language: {$needle}\n");exit(1);}
foreach(['merchant_name','void_reason','note','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($agent,$bad)!==false){fwrite(STDERR,"Trip cost agent context exposes private field: {$bad}\n");exit(1);}

$page=$read('trip-cost.php');foreach(['Trip Cost Intelligence · True Trip Economics','No hidden exchange rate','Provider credits are shown separately from cash recovered','Actual expense ledger','Category budget','Learned trip-cost model','Trip Memory actual spend is traveler-reported','verify_csrf()','name="_csrf"','void_expense','trip-resolution.php?id=','trip-memory.php?id='] as $needle)if(strpos($page,$needle)===false){fwrite(STDERR,"Trip cost UI missing {$needle}\n");exit(1);}
foreach(['openHandoff','executeBookingComCancellation','prepareBookingComCancellation','navigator.geolocation','getCurrentPosition','watchPosition'] as $bad)if(strpos($page,$bad)!==false){fwrite(STDERR,"Trip cost UI must not execute provider/location actions: {$bad}\n");exit(1);}

$boot=$read('app/bootstrap.php');foreach(['/Services/TripCostIntelligenceService.php','/Services/TripCostIntelligenceAgentContextService.php'] as $needle)if(strpos($boot,$needle)===false){fwrite(STDERR,"Trip cost bootstrap missing {$needle}\n");exit(1);}
$bookingAgent=$read('app/Services/BookingActionAgentContextService.php');foreach(['TripCostIntelligenceAgentContextService','$costs->context($userId,3)'] as $needle)if(strpos($bookingAgent,$needle)===false){fwrite(STDERR,"Main agent missing Trip Cost Intelligence context: {$needle}\n");exit(1);}
$tabs=$read('assets/trip-workspace-tabs.js');foreach(["['costs','Costs']",'trip-cost.php?id=','Open True Trip Economics'] as $needle)if(strpos($tabs,$needle)===false){fwrite(STDERR,"Trip workspace missing Costs entry: {$needle}\n");exit(1);}
$css=$read('assets/trip-cost-intelligence.css');foreach(['vb-cost-head','vb-cost-summary','vb-cost-currency','vb-cost-table','vb-cost-ledger','vb-cost-model','@media(max-width:620px)'] as $needle)if(strpos($css,$needle)===false){fwrite(STDERR,"Trip cost styling missing {$needle}\n");exit(1);}

echo "Trip Cost Intelligence + True Trip Economics contract OK\n";
