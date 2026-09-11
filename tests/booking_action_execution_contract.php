<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/048_booking_action_execution.sql',
    'app/Services/TripBookingActionService.php',
    'app/Services/BookingActionAgentContextService.php',
    'app/Services/VacationAgentService.php',
    'booking-action.php',
    'trip-bookings.php',
    'trip-execution.php',
    'assets/booking-actions.css',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Booking & Action Execution file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/048_booking_action_execution.sql');
foreach(['CREATE TABLE trip_booking_action_intents','CREATE TABLE trip_booking_action_quotes','CREATE TABLE trip_booking_action_receipts','idempotency_key','provider_state_encrypted','provider_state_hash','current_quote_id','quote_digest','expires_at','awaiting_approval','verification_pending',"'app_version','1.40'"] as $needle){if(strpos($m,$needle)===false){fwrite(STDERR,"Booking action migration missing {$needle}\n");exit(1);}}

$service=$read('app/Services/TripBookingActionService.php');
foreach(['class TripBookingActionService','ensureHandoffIntent','prepareHandoff','prepareBookingComCancellation','function approve','function execute','openHandoff','confirmHandoff','quoteDigest','quote_expires_at','provider_state_encrypted','provider_state_hash','aes-256-gcm','/orders/details/accommodations','/orders/cancel','Booking.com cancellation terms changed','verification_pending','updateBookingStatus','provider_handoff','booking_com_cancel','quote expired','Payment-card data is never accepted or stored','provider-hosted checkout','enrichBookingComState','reservation reference required for a safe cancellation request','TripTravelOperationsService','ProactiveTravelService',"'booking_action'",'syncAfterAction'] as $needle){if(stripos($service,$needle)===false){fwrite(STDERR,"Booking action service missing {$needle}\n");exit(1);}}
foreach(['card_number','credit_card_number','cvv','cvc','security_code'] as $forbidden){if(stripos($service,$forbidden)!==false){fwrite(STDERR,"Booking action service must not collect payment-card field {$forbidden}.\n");exit(1);}}
if(strpos($service,"updateBookingStatus(\$userId,\$tripId,\$bookingId,'confirmed')")!==false){fwrite(STDERR,"Provider handoff confirmation must not claim provider-verified Confirmed status.\n");exit(1);}
if(strpos($service,"updateBookingStatus(\$userId,\$tripId,\$bookingId,'booked')")===false){fwrite(STDERR,"Provider handoff must record user-confirmed completion as Booked.\n");exit(1);}
if(strpos($service,"status='approved'")===false||strpos($service,"status='executing'")===false){fwrite(STDERR,"Direct provider action must preserve separate approval/execution states.\n");exit(1);}
if(stripos($service,'blindly retried')===false&&stripos($service,'retry blindly')===false){fwrite(STDERR,"Direct destructive actions need an explicit no-blind-retry boundary.\n");exit(1);}
if(strpos($service,"'reservation'=>(string)\$state['reservation']")===false){fwrite(STDERR,"Booking.com cancellation payload must require the resolved accommodation reservation reference.\n");exit(1);}

$page=$read('booking-action.php');
foreach(['require_auth','verify_csrf','Review before anything happens','transaction approval','Approve this provider action','Execute approved cancellation','Open provider checkout','I completed the booking','I did not complete it','operational references are encrypted at rest','Nothing is cancelled by this form','Do not retry'] as $needle){if(stripos($page,$needle)===false){fwrite(STDERR,"Booking action review UI missing {$needle}\n");exit(1);}}
foreach(['name="card_number"','name="cvv"','name="cvc"'] as $forbidden){if(stripos($page,$forbidden)!==false){fwrite(STDERR,"Booking action UI must not collect payment-card fields.\n");exit(1);}}

$bookings=$read('trip-bookings.php');
foreach(['TripBookingActionService','Prepare booking action','Review booking action','Check cancellation','migration 048','never collected by Vacation Brain','booking-action.php'] as $needle){if(stripos($bookings,$needle)===false){fwrite(STDERR,"Booking & Readiness integration missing {$needle}\n");exit(1);}}

$execution=$read('trip-execution.php');
foreach(['TripBookingActionService','ensureHandoffIntent','transaction still requires its own locked quote and explicit approval','Confirm live availability'] as $needle){if(stripos($execution,$needle)===false){fwrite(STDERR,"Agent handoff integration missing {$needle}\n");exit(1);}}

$agentContext=$read('app/Services/BookingActionAgentContextService.php');
foreach(['class BookingActionAgentContextService','BOOKING ACTION STATE','saved transaction ledger only','no provider refresh','Planning approval is separate from transaction approval','verification_pending','user-confirmed Booked','payment data are excluded','function active'] as $needle){if(stripos($agentContext,$needle)===false){fwrite(STDERR,"Safe booking action agent context missing {$needle}\n");exit(1);}}
foreach(['provider_state_encrypted','confirmation_code','private_notes','payment_details','card_number'] as $forbidden){if(strpos($agentContext,$forbidden)!==false){fwrite(STDERR,"Booking action agent context must not query sensitive identifier {$forbidden}.\n");exit(1);}}

$agent=$read('app/Services/VacationAgentService.php');
foreach(['BookingActionAgentContextService','BOOKING ACTION STATE','isBookingActionQuestion','bookingActionFallback','what needs approval','did it book','did it cancel','verification pending','did not contact a booking provider','Do not retry it'] as $needle){if(stripos($agent,$needle)===false){fwrite(STDERR,"Main Vacation Brain booking-action grounding missing {$needle}\n");exit(1);}}

$bootstrap=$read('app/bootstrap.php');
foreach(['/Services/TripBookingActionService.php','/Services/BookingActionAgentContextService.php'] as $needle){if(strpos($bootstrap,$needle)===false){fwrite(STDERR,"Booking action bootstrap missing {$needle}\n");exit(1);}}
$css=$read('assets/booking-actions.css');foreach(['vb-ba-compare','vb-ba-terms','vb-ba-timeline','vb-ba-danger'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Booking action styling missing {$needle}\n");exit(1);}}

echo "Booking & Action Execution contract OK\n";
