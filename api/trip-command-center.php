<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();
try{
    $pdo=db();$snapshot=(new TripCommandCenterService($pdo))->snapshot($userId);$bookingService=new TripBookingService($pdo);if($bookingService->ready())$snapshot=$bookingService->augmentCommandCenterSnapshot($userId,$snapshot);
    echo json_encode(['ok'=>true,'command_center'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(InvalidArgumentException $e){
    http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('Trip Command Center API failed: '.$e->getMessage());
    http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Trip Command Center is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
