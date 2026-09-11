<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$importId=max(0,(int)($_GET['id']??0));
try{
    $source=(new TripBookingImportService(db()))->source($userId,$importId);
    header('Content-Type: '.$source['mime']);
    header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'',(string)$source['filename']).'"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo (string)$source['body'];
}catch(OutOfBoundsException $e){http_response_code(404);exit('Booking Inbox item not found.');}
catch(Throwable $e){error_log('Booking Inbox source download failed: '.$e->getMessage());http_response_code(500);exit('Vacation Brain could not open that private confirmation source.');}
