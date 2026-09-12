<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$messageId=max(0,(int)($_GET['id']??0));
try{
    $source=(new BookingMailboxService(db()))->source($userId,$messageId);
    header('Content-Type: '.$source['mime']);
    header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',(string)$source['filename']).'"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo (string)$source['body'];
}catch(OutOfBoundsException $e){http_response_code(404);exit('Connected-mail message not found.');}
catch(Throwable $e){error_log('Connected Booking Inbox source failed: '.$e->getMessage());http_response_code(500);exit('Vacation Brain could not open that private redacted mail source.');}
