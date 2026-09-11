<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/052_booking_inbox.sql','app/Services/TripBookingImportService.php','app/Services/BookingImportAgentContextService.php',
    'app/Services/BookingActionAgentContextService.php','booking-inbox.php','booking-inbox-document.php','assets/booking-inbox.css',
    'dream.php','partials/trip-command-center.php','api/trip-command-center.php','app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Booking Inbox file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$migration=$read('db/052_booking_inbox.sql');
foreach(['CREATE TABLE trip_booking_imports','CREATE TABLE trip_booking_import_events','source_encrypted LONGTEXT','sensitive_encrypted LONGTEXT','uq_booking_import_source','uq_booking_import_fingerprint',"'app_version','1.44'"] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Booking Inbox migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TripBookingImportService.php');
foreach([
    'final class TripBookingImportService','trip_bookings','booking_fingerprint','source_hash','aes-256-gcm','redactPaymentData',
    'booking_import_parse','$useAi',"source=\\'import\\'",'booking_id','match_confidence','augmentCommandCenterSnapshot','safeAgentContext',
    'provider_url','flight_number','departure_iata','arrival_iata','array_intersect_key','autoMaterializeEligible','markNeedsReview',
    'finishImportLink','assertActiveTrip',"hash('sha256',\$sourceType.'|'.\$sourceForHash)",
    "([A-Z]{2}|[A-Z][0-9]|[0-9][A-Z])\\s?([0-9]{1,4})",
] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Booking Inbox service missing {$needle}\n");exit(1);}}
if(strpos($service,".($filename??'').'|'.")!==false){fwrite(STDERR,"Booking Inbox source dedupe must not depend on the uploaded filename.\n");exit(1);}
if(strpos($service,"status='confirmed'")!==false||strpos($service,"'confirmed','unknown'")!==false){fwrite(STDERR,"Booking Inbox must not automatically mark imported reservations provider-verified Confirmed.\n");exit(1);}
if(strpos($service,"'booked','unknown'")===false){fwrite(STDERR,"Imported canonical reservations must begin as Booked, not Confirmed.\n");exit(1);}
if(strpos($service,"if($useAi&&trim($text)!=='')")===false){fwrite(STDERR,"AI booking parsing must remain explicit opt-in per import.\n");exit(1);}
if(strpos($service,'[payment-card number removed]')===false||strpos($service,'security code')===false){fwrite(STDERR,"Booking Inbox payment-data redaction guard is missing.\n");exit(1);}
foreach(['TripBookingActionService','executeDirectCancellation','providerCheckout','curl_exec'] as $forbidden){if(strpos($service,$forbidden)!==false){fwrite(STDERR,"Booking Inbox must not execute provider or transaction actions: {$forbidden}\n");exit(1);}}

$page=$read('booking-inbox.php');
foreach(['Booking Inbox','source_text','confirmation_file','Auto-match to an active trip','use_ai','explicitly sends the redacted confirmation text','auto_add','Review & link booking','Traveler verified','booking-inbox-document.php','migration 052','not provider-verified Confirmed'] as $needle){if(strpos($page,$needle)===false){fwrite(STDERR,"Booking Inbox UI missing {$needle}\n");exit(1);}}
if(strpos($page,'name="_csrf"')===false){fwrite(STDERR,"Booking Inbox mutations must remain CSRF protected.\n");exit(1);}

$doc=$read('booking-inbox-document.php');
foreach(['require_auth','Cache-Control: private, no-store','X-Content-Type-Options: nosniff','->source($userId,$importId)'] as $needle){if(strpos($doc,$needle)===false){fwrite(STDERR,"Private Booking Inbox source endpoint missing {$needle}\n");exit(1);}}

$agent=$read('app/Services/BookingImportAgentContextService.php');
foreach(['BOOKING INBOX STATE','parsed_json','confirmation codes','payment data','booking_id IS NOT NULL'] as $needle){if(strpos($agent,$needle)===false){fwrite(STDERR,"Booking Inbox safe agent context missing {$needle}\n");exit(1);}}
foreach(['source_encrypted','sensitive_encrypted','confirmation_code FROM','original_filename FROM'] as $forbidden){if(strpos($agent,$forbidden)!==false){fwrite(STDERR,"Booking Inbox agent context must never query private source/sensitive fields: {$forbidden}\n");exit(1);}}
$bookingContext=$read('app/Services/BookingActionAgentContextService.php');if(strpos($bookingContext,'BookingImportAgentContextService')===false){fwrite(STDERR,"Main agent booking context is not connected to safe Booking Inbox facts.\n");exit(1);}

$dream=$read('dream.php');if(strpos($dream,"app_url('booking-inbox.php')")===false){fwrite(STDERR,"Plan Trip hub is missing Booking Inbox navigation.\n");exit(1);}
foreach(['partials/trip-command-center.php','api/trip-command-center.php'] as $file){$body=$read($file);foreach(['TripBookingImportService','augmentCommandCenterSnapshot','booking-inbox.php'] as $needle){if(strpos($body,$needle)===false&&$needle==='booking-inbox.php'&&$file==='api/trip-command-center.php')continue;if(strpos($body,$needle)===false){fwrite(STDERR,"Command Center integration missing {$needle} in {$file}\n");exit(1);}}}

$bootstrap=$read('app/bootstrap.php');foreach(['/Services/TripBookingImportService.php','/Services/BookingImportAgentContextService.php'] as $needle){if(strpos($bootstrap,$needle)===false){fwrite(STDERR,"Booking Inbox bootstrap missing {$needle}\n");exit(1);}}
$css=$read('assets/booking-inbox.css');foreach(['vb-booking-inbox','vb-bi-import','vb-bi-review','vb-bi-privacy'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Booking Inbox styling missing {$needle}\n");exit(1);}}

echo "Booking Inbox + Automatic Trip Import contract OK\n";
