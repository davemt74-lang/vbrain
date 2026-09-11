<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/046_live_travel_providers.sql',
    'app/Services/TravelProviderSettingsService.php',
    'app/Services/LiveTravelDataProviderService.php',
    'app/Services/LiveTravelAgentContextService.php',
    'app/Services/TripFlightTrackingService.php',
    'app/Services/TripIntelligenceService.php',
    'app/Services/TripLiveIntelligenceService.php',
    'app/Services/TripAgentService.php',
    'app/Services/VacationAgentService.php',
    'app/Services/TravelWatchService.php',
    'admin/travel-providers.php',
    'trip-lodging.php',
    'flight-tracking.php',
    'partials/live-travel-data-panel.php',
    'partials/flight-tracking-panel.php',
    'assets/live-travel-data.css',
    'assets/live-travel-data.js',
    'assets/travel-providers.css',
    'config-example.php',
    'app/bootstrap.php',
    'partials/footer.php',
    'watches.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Live Travel Data file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/046_live_travel_providers.sql');
foreach(['CREATE TABLE travel_provider_settings','CREATE TABLE travel_provider_usage_log','api_key_encrypted','aviationstack','booking_com','flight_number','departure_iata','arrival_iata','watch_lodging',"'app_version','1.38'"] as $n){if(strpos($m,$n)===false){fwrite(STDERR,"Live provider migration missing {$n}\n");exit(1);}}

$settings=$read('app/Services/TravelProviderSettingsService.php');
foreach(['class TravelProviderSettingsService','AES-256-GCM','aes-256-gcm','function effectiveKey','function save','function clearKey','function test','function recordUsage','VISUAL_CROSSING_API_KEY','AVIATIONSTACK_API_KEY','BOOKING_COM_API_TOKEN','booking_com_affiliate_id','maskedKey'] as $n){if(strpos($settings,$n)===false){fwrite(STDERR,"Travel provider settings service missing {$n}\n");exit(1);}}
foreach(["unset(\$row['api_key_encrypted']","api_key_encrypted=NULL"] as $n){if(strpos($settings,$n)===false){fwrite(STDERR,"Travel provider secret handling missing {$n}\n");exit(1);}}

$provider=$read('app/Services/LiveTravelDataProviderService.php');
foreach(['class LiveTravelDataProviderService','Booking.com Demand API','demandapi.booking.com/3.2','/accommodations/search','/accommodations/details','Aviationstack','api.aviationstack.com/v1/flights','booked_statuses','live_status_count','Skyscanner Indicative Prices','accuracy_note','flight_number','departure_iata','arrival_iata'] as $n){if(strpos($provider,$n)===false){fwrite(STDERR,"Live provider bridge missing {$n}\n");exit(1);}}
foreach(['confirmation_code','private_notes'] as $forbidden){if(strpos($provider,$forbidden)!==false){fwrite(STDERR,"Live provider bridge must not consume {$forbidden}.\n");exit(1);}}
if(preg_match('/\bnotes\b/',$provider)){fwrite(STDERR,"Live provider bridge must not send booking notes to providers.\n");exit(1);}

$intel=$read('app/Services/TripIntelligenceService.php');foreach(['LiveTravelDataProviderService',"'lodging'",'lodging_estimate',"$type==='lodging'?'hotel'"] as $n){if(strpos($intel,$n)===false){fwrite(STDERR,"Trip intelligence lodging integration missing {$n}\n");exit(1);}}
$live=$read('app/Services/TripLiveIntelligenceService.php');foreach(["['weather','flights','lodging','events','places']",'live_status_count',"$type==='lodging'",'flightStatusChange','gate changed'] as $n){if(strpos($live,$n)===false){fwrite(STDERR,"Live freshness/change layer missing {$n}\n");exit(1);}}
$agent=$read('app/Services/TripAgentService.php');foreach(['lodging','Aviationstack booked-flight status','Booking.com lodging results','Never invent live weather','confirmed reservations'] as $n){if(strpos($agent,$n)===false){fwrite(STDERR,"Trip agent live-provider grounding missing {$n}\n");exit(1);}}

$context=$read('app/Services/LiveTravelAgentContextService.php');foreach(['saved snapshots only','never includes booking confirmation codes','booked_statuses','lodging snapshot','fallbackSummary','no provider refresh was triggered'] as $n){if(stripos($context,$n)===false){fwrite(STDERR,"Main-agent live context missing {$n}\n");exit(1);}}
foreach(['confirmation_code','private_notes'] as $forbidden){if(strpos($context,$forbidden)!==false){fwrite(STDERR,"Main-agent live context must not query {$forbidden}.\n");exit(1);}}
$mainAgent=$read('app/Services/VacationAgentService.php');foreach(['LiveTravelAgentContextService','LIVE TRIP CONTEXT','saved provider snapshots','does not have a successful saved lodging snapshot','indicative airfare'] as $n){if(strpos($mainAgent,$n)===false){fwrite(STDERR,"Main Vacation Brain live-data grounding missing {$n}\n");exit(1);}}

$tracking=$read('app/Services/TripFlightTrackingService.php');foreach(['class TripFlightTrackingService','flight_number','departure_iata','arrival_iata','user_id=? AND dream_trip_id=?','flight_tracking_updated'] as $n){if(strpos($tracking,$n)===false){fwrite(STDERR,"Flight tracking service missing {$n}\n");exit(1);}}
$route=$read('flight-tracking.php');foreach(['require_auth','verify_csrf','TripFlightTrackingService','booking_id','trip-bookings.php?id='] as $n){if(strpos($route,$n)===false){fwrite(STDERR,"Flight tracking route missing {$n}\n");exit(1);}}
$panel=$read('partials/flight-tracking-panel.php');foreach(['Live flight status','Confirmation codes and booking notes are never sent','Flight number','departure_iata','arrival_iata'] as $n){if(strpos($panel,$n)===false){fwrite(STDERR,"Flight tracking panel missing {$n}\n");exit(1);}}

$admin=$read('admin/travel-providers.php');foreach(['require_admin','verify_csrf','TravelProviderSettingsService','Server-side credentials only','Save & test','Booking.com Demand API','Aviationstack','Skyscanner','travel_provider_usage_log'] as $n){if(strpos($admin,$n)===false){fwrite(STDERR,"Travel provider admin missing {$n}\n");exit(1);}}
if(strpos($admin,'api_key_encrypted')!==false){fwrite(STDERR,"Travel provider admin must never render encrypted credential columns.\n");exit(1);}

$lodging=$read('trip-lodging.php');foreach(['require_auth','verify_csrf','Live lodging','TripIntelligenceService',"['lodging']",'addFromSnapshot','not a confirmed reservation','Booking.com Demand API'] as $n){if(strpos($lodging,$n)===false){fwrite(STDERR,"Live lodging page missing {$n}\n");exit(1);}}
$panel=$read('partials/live-travel-data-panel.php');foreach(['Live Trip Data','Flights','Weather','Lodging','Events','Places','TripLiveIntelligenceService'] as $n){if(strpos($panel,$n)===false){fwrite(STDERR,"Live trip data health panel missing {$n}\n");exit(1);}}

$watch=$read('app/Services/TravelWatchService.php');foreach(['watch_lodging','lodging_drop','lodging_rise','lodging_change','flight_status','flight_gate','supportsLodging','trip-lodging.php'] as $n){if(strpos($watch,$n)===false){fwrite(STDERR,"Travel watch live-provider integration missing {$n}\n");exit(1);}}
$watchPage=$read('watches.php');foreach(['Weather','Flights','Lodging','Events','Local','watch_lodging','no second worker is required'] as $n){if(stripos($watchPage,$n)===false){fwrite(STDERR,"Travel watch UI missing {$n}\n");exit(1);}}

$config=$read('config-example.php');foreach(['aviationstack_key','AVIATIONSTACK_API_KEY','booking_com_token','BOOKING_COM_API_TOKEN','booking_com_affiliate_id','booking_com_environment'] as $n){if(strpos($config,$n)===false){fwrite(STDERR,"Live provider config fallback missing {$n}\n");exit(1);}}
$bootstrap=$read('app/bootstrap.php');foreach(['TravelProviderSettingsService.php','LiveTravelDataProviderService.php','LiveTravelAgentContextService.php','TripFlightTrackingService.php'] as $n){if(strpos($bootstrap,$n)===false){fwrite(STDERR,"Bootstrap missing {$n}\n");exit(1);}}
$footer=$read('partials/footer.php');foreach(['live-travel-data-panel.php','flight-tracking-panel.php','live-travel-data.css','live-travel-data.js'] as $n){if(strpos($footer,$n)===false){fwrite(STDERR,"Global live travel UI integration missing {$n}\n");exit(1);}}

echo "Live Travel Providers contract OK\n";
