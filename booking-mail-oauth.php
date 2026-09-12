<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();
$service=new BookingMailboxService(db());
try{
    if(isset($_GET['error']))throw new DomainException('Google did not authorize the Gmail connection: '.trim((string)$_GET['error']).'.');
    $service->completeGoogleOAuth($userId,trim((string)($_GET['code']??'')),trim((string)($_GET['state']??'')));
    flash('success','Gmail connected to Booking Inbox with read-only access.');
}catch(InvalidArgumentException|DomainException $e){flash('error',$e->getMessage());}
catch(Throwable $e){error_log('Booking mail OAuth failed: '.$e->getMessage());flash('error','Vacation Brain could not finish the Gmail connection. Check the server OAuth configuration and try again.');}
redirect('booking-mailbox.php');
