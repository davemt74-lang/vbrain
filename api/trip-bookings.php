<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
$tripId=(int)($_GET['trip_id']??0);
try{
    $snapshot=(new TripBookingService(db()))->snapshot($userId,$tripId);
    echo json_encode(['ok'=>true,'booking'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(InvalidArgumentException|OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('Trip bookings API failed: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Trip booking readiness is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
