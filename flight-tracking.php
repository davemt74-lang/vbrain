<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$tripId=(int)($_POST['trip_id']??0);$bookingId=(int)($_POST['booking_id']??0);
if($_SERVER['REQUEST_METHOD']!=='POST'){redirect('dream.php');}
verify_csrf();
try{(new TripFlightTrackingService(db()))->save($userId,$tripId,$bookingId,$_POST);flash('success','Live flight tracking details updated.');}
catch(InvalidArgumentException|OutOfBoundsException|RuntimeException $e){flash('error',$e->getMessage());}
catch(Throwable $e){error_log('Flight tracking update failed: '.$e->getMessage());flash('error','Vacation Brain could not update flight tracking right now.');}
redirect('trip-bookings.php?id='.$tripId.'#live-flight-tracking');
