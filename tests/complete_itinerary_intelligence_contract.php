<?php
declare(strict_types=1);

$root=dirname(__DIR__);$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';
$files=['db/055_complete_itinerary_intelligence.sql','app/Services/TripItineraryIntelligenceService.php','app/Services/TripItineraryAgentContextService.php','trip-itinerary.php','partials/trip-itinerary-intelligence.php','assets/trip-itinerary-intelligence.css','assets/trip-workspace-tabs.js','partials/footer.php','bin/run-travel-watches.php','app/Services/BookingActionAgentContextService.php','app/bootstrap.php'];
foreach($files as $file)if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Complete Itinerary Intelligence file: {$file}\n");exit(1);}

$m=$read('db/055_complete_itinerary_intelligence.sql');
foreach(['itinerary_analyzed_at','ADD COLUMN starts_at DATETIME','ADD COLUMN ends_at DATETIME','duration_minutes','timing_mode','location_name','latitude DECIMAL','longitude DECIMAL','buffer_before_minutes','buffer_after_minutes','CREATE TABLE trip_itinerary_preferences','CREATE TABLE trip_itinerary_issues','issue_key','resolved_at',"'app_version','1.47'"] as $needle)if(strpos($m,$needle)===false){fwrite(STDERR,"Complete Itinerary migration missing {$needle}\n");exit(1);}

$s=$read('app/Services/TripItineraryIntelligenceService.php');
foreach(['final class TripItineraryIntelligenceService','snapshot','savePreferences','saveItemTiming','applySuggestedOrder','safeAgentContext','runUpcoming','sequenceDay','geoOrder','analyzeDay','tripBoundaryIssues','syncDerivedState','syncTripInbox','bookingBufferBefore','default_transfer_minutes','derived_time','source_type,source_key','itinerary_issue',"['owner','co_planner','traveler']"] as $needle)if(strpos($s,$needle)===false){fwrite(STDERR,"Complete Itinerary service missing {$needle}\n");exit(1);}
foreach(['TripBookingActionService','BookingMailboxService','LiveTravelDataProviderService','executeDirectCancellation','providerCheckout','completeGoogleOAuth','curl_init','getCurrentPosition','watchPosition','navigator.geolocation','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($s,$bad)!==false){fwrite(STDERR,"Complete Itinerary crossed a provider/private boundary: {$bad}\n");exit(1);}
if(strpos($s,"$entry['source']==='booking'||$mode==='fixed'")===false){fwrite(STDERR,"Confirmed bookings must remain fixed itinerary anchors.\n");exit(1);}
if(strpos($s,"!$entry['fixed']&&!empty($entry['derived_time'])")===false){fwrite(STDERR,"Geographic ordering must be limited to flexible derived-time items.\n");exit(1);}
if(strpos($s,'Only the trip owner or a Co-planner can change the shared itinerary.')===false){fwrite(STDERR,"Role-aware itinerary mutation gate missing.\n");exit(1);}

$agent=$read('app/Services/TripItineraryAgentContextService.php');foreach(['ITINERARY INTELLIGENCE','no provider, mailbox, or device-location refresh','Fixed reservations are never silently moved','owner or Co-planner','exact current-device coordinates','reservation, purchase, cancellation'] as $needle)if(strpos($agent,$needle)===false){fwrite(STDERR,"Itinerary agent safety context missing {$needle}\n");exit(1);}
foreach(['latitude','longitude','confirmation_code','provider_state_encrypted','access_token_encrypted','refresh_token_encrypted'] as $bad)if(strpos($agent,$bad)!==false){fwrite(STDERR,"Itinerary agent context must not expose exact/private source fields: {$bad}\n");exit(1);}

$page=$read('trip-itinerary.php');foreach(['Complete Itinerary Intelligence','Door-to-door timing','Apply suggested flexible order','Save & recheck','Timing policy','Planning math is not a provider action','Travel Mode','Trip Inbox','migration 055','name="_csrf"'] as $needle)if(strpos($page,$needle)===false){fwrite(STDERR,"Complete Itinerary UI missing {$needle}\n");exit(1);}
foreach(['providerCheckout','executeDirectCancellation','completeGoogleOAuth','getCurrentPosition','watchPosition'] as $bad)if(strpos($page,$bad)!==false){fwrite(STDERR,"Complete Itinerary UI must not execute external/provider actions: {$bad}\n");exit(1);}

$workspace=$read('assets/trip-workspace-tabs.js');foreach(["['timeline','Timeline']",'.vb-itinerary-intelligence-wrap','workspace','trip-itinerary.php?id='] as $needle)if(strpos($workspace,$needle)===false){fwrite(STDERR,"Trip workspace Timeline integration missing {$needle}\n");exit(1);}
$partial=$read('partials/trip-itinerary-intelligence.php');foreach(['Complete itinerary intelligence','Fixed anchors','High conflicts','Leave by','Open full timeline','Local Agent'] as $needle)if(strpos($partial,$needle)===false){fwrite(STDERR,"Trip Timeline workspace partial missing {$needle}\n");exit(1);}
$footer=$read('partials/footer.php');foreach(['trip-itinerary-intelligence.php','assets/trip-itinerary-intelligence.css'] as $needle)if(strpos($footer,$needle)===false){fwrite(STDERR,"Timeline workspace/footer integration missing {$needle}\n");exit(1);}

$runner=$read('bin/run-travel-watches.php');foreach(['TripItineraryIntelligenceService',"$result['itinerary_intelligence']",'runUpcoming($limit)'] as $needle)if(strpos($runner,$needle)===false){fwrite(STDERR,"Complete Itinerary must reuse the existing travel-watch worker.\n");exit(1);}
$bookingAgent=$read('app/Services/BookingActionAgentContextService.php');foreach(['TripItineraryAgentContextService','$itinerary->context($userId,3)'] as $needle)if(strpos($bookingAgent,$needle)===false){fwrite(STDERR,"Main Vacation Brain agent is missing safe itinerary context: {$needle}\n");exit(1);}
$boot=$read('app/bootstrap.php');foreach(['/Services/TripItineraryIntelligenceService.php','/Services/TripItineraryAgentContextService.php'] as $needle)if(strpos($boot,$needle)===false){fwrite(STDERR,"Itinerary bootstrap missing {$needle}\n");exit(1);}
$css=$read('assets/trip-itinerary-intelligence.css');foreach(['vb-itinerary-intelligence-wrap','vb-itinerary-timeline','vb-itinerary-row','trip-itinerary-editor','@media(max-width:620px)'] as $needle)if(strpos($css,$needle)===false){fwrite(STDERR,"Itinerary styling missing {$needle}\n");exit(1);}

if(function_exists('exec')){$output=[];$code=0;exec('node --check '.escapeshellarg($root.'/assets/trip-workspace-tabs.js').' 2>&1',$output,$code);if($code!==0){fwrite(STDERR,"Timeline workspace JavaScript syntax failed: ".implode("\n",$output)."\n");exit(1);}}

echo "Complete Itinerary Intelligence contract OK\n";
