<?php
declare(strict_types=1);

$root=dirname(__DIR__);$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';
$files=['db/056_travel_day_copilot.sql','app/Services/TripTravelDayCopilotService.php','app/Services/TripTravelDayCopilotAgentContextService.php','api/travel-copilot.php','assets/travel-day-copilot.css','assets/travel-mode.js','assets/travel-mode-offline.js','service-worker.php','bin/run-travel-watches.php','app/Services/BookingActionAgentContextService.php','app/bootstrap.php'];
foreach($files as $file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Travel Day Copilot file: {$file}\n");exit(1);}

$m=$read('db/056_travel_day_copilot.sql');
foreach(['travel_copilot_checked_at','CREATE TABLE trip_copilot_item_states','traveler_state','running_late','need_help','delay_minutes','CREATE TABLE trip_copilot_checklist_items','checklist_date','completed_at','CREATE TABLE trip_copilot_briefings','pre_departure','end_of_day',"'app_version','1.48'"] as $needle)if(strpos($m,$needle)===false){fwrite(STDERR,"Travel Day Copilot migration missing {$needle}\n");exit(1);}
foreach(['card_number','payment_method','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($m,$bad)!==false){fwrite(STDERR,"Travel Day Copilot schema crossed a private/payment boundary: {$bad}\n");exit(1);}

$s=$read('app/Services/TripTravelDayCopilotService.php');
foreach(['final class TripTravelDayCopilotService','snapshot','setItemState','setChecklistCompleted','safeAgentContext','runUpcoming','offlineSnapshot','focus','countdown','ripples','ensureChecklist','briefing','syncInbox','Travel Day Copilot 2.0','TripItineraryIntelligenceService','trip_copilot_item_states','travel_copilot'] as $needle)if(strpos($s,$needle)===false){fwrite(STDERR,"Travel Day Copilot service missing {$needle}\n");exit(1);}
foreach(['TripBookingActionService','BookingMailboxService','executeDirectCancellation','providerCheckout','completeGoogleOAuth','curl_init','getCurrentPosition','watchPosition','navigator.geolocation','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted','payment_method'] as $bad)if(strpos($s,$bad)!==false){fwrite(STDERR,"Travel Day Copilot crossed an execution/location/private boundary: {$bad}\n");exit(1);}
if(strpos($s,"in_array((string)(\$e['traveler_state']??''),['done','skipped'],true)")===false){fwrite(STDERR,"Copilot must respect traveler-declared done/skipped state when selecting focus.\n");exit(1);}
if(strpos($s,"\$state==='running_late'")===false){fwrite(STDERR,"Copilot running-late propagation gate missing.\n");exit(1);}

$agent=$read('app/Services/TripTravelDayCopilotAgentContextService.php');foreach(['TRAVEL DAY COPILOT','no provider, mailbox, or device-location refresh','saved leave-by timing','Do not claim live traffic/security conditions','booking, cancellation, purchase, refund, checkout or provider changes'] as $needle)if(strpos($agent,$needle)===false){fwrite(STDERR,"Copilot agent safety context missing {$needle}\n");exit(1);}
foreach(['latitude','longitude','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($agent,$bad)!==false){fwrite(STDERR,"Copilot agent context must not expose exact/private fields: {$bad}\n");exit(1);}

$api=$read('api/travel-copilot.php');foreach(['TripTravelDayCopilotService','REQUEST_METHOD','GET','POST','verify_csrf','item_state','checklist','setItemState','setChecklistCompleted',"['owner','co_planner','traveler']",'Viewer role is read-only in Travel Day Copilot.'] as $needle)if(strpos($api,$needle)===false){fwrite(STDERR,"Copilot API missing {$needle}\n");exit(1);}
foreach(['booking_ops','executeDirectCancellation','providerCheckout','curl_init'] as $bad)if(strpos($api,$bad)!==false){fwrite(STDERR,"Copilot API must not expose provider transaction paths: {$bad}\n");exit(1);}

$js=$read('assets/travel-mode.js');foreach(['api/travel-copilot.php','travel-day-copilot.css','Travel Day Copilot','data-copilot-state','running_late','need_help','data-copilot-check','cfg.copilotOffline','Sensitive confirmation details were excluded','Viewer access','Live plan is read-only',"['owner','co_planner','traveler']",'updateCountdowns'] as $needle)if(strpos($js,$needle)===false){fwrite(STDERR,"Travel Mode Copilot UI missing {$needle}\n");exit(1);}
foreach(['navigator.geolocation','getCurrentPosition','watchPosition'] as $bad)if(strpos($js,$bad)!==false){fwrite(STDERR,"Travel Day Copilot must not initiate device geolocation: {$bad}\n");exit(1);}
$offline=$read('assets/travel-mode-offline.js');foreach(['Saved Travel Day Copilot','copilot.checklist','copilot.ripples','No confirmation codes, provider links, payment data or private booking notes'] as $needle)if(strpos($offline,$needle)===false){fwrite(STDERR,"Offline Travel Mode missing Copilot-safe content: {$needle}\n");exit(1);}
$sw=$read('service-worker.php');foreach(["vacation-brain-shell-v3",'assets/travel-mode-offline.js','travel-mode-offline.html'] as $needle)if(strpos($sw,$needle)===false){fwrite(STDERR,"Travel Day Copilot offline shell refresh missing {$needle}\n");exit(1);}
$css=$read('assets/travel-day-copilot.css');foreach(['vb-copilot-hero','vb-copilot-countdown','vb-copilot-actions','vb-copilot-ripples','vb-copilot-checklist','@media(max-width:560px)'] as $needle)if(strpos($css,$needle)===false){fwrite(STDERR,"Travel Day Copilot styling missing {$needle}\n");exit(1);}

$runner=$read('bin/run-travel-watches.php');foreach(['TripTravelDayCopilotService',"\$result['travel_day_copilot']",'runUpcoming($limit)'] as $needle)if(strpos($runner,$needle)===false){fwrite(STDERR,"Travel Day Copilot must reuse the existing travel-watch worker.\n");exit(1);}
$bookingAgent=$read('app/Services/BookingActionAgentContextService.php');foreach(['TripTravelDayCopilotAgentContextService','$copilot->context($userId,3)'] as $needle)if(strpos($bookingAgent,$needle)===false){fwrite(STDERR,"Main Vacation Brain agent is missing Copilot context: {$needle}\n");exit(1);}
$boot=$read('app/bootstrap.php');foreach(['/Services/TripTravelDayCopilotService.php','/Services/TripTravelDayCopilotAgentContextService.php'] as $needle)if(strpos($boot,$needle)===false){fwrite(STDERR,"Travel Day Copilot bootstrap missing {$needle}\n");exit(1);}

if(function_exists('exec')){$output=[];$code=0;exec('node --check '.escapeshellarg($root.'/assets/travel-mode.js').' 2>&1',$output,$code);if($code!==0){fwrite(STDERR,"Travel Day Copilot JavaScript syntax failed: ".implode("\n",$output)."\n");exit(1);}exec('node --check '.escapeshellarg($root.'/assets/travel-mode-offline.js').' 2>&1',$output,$code);if($code!==0){fwrite(STDERR,"Offline Copilot JavaScript syntax failed: ".implode("\n",$output)."\n");exit(1);}}

echo "Travel Day Copilot 2.0 contract OK\n";
