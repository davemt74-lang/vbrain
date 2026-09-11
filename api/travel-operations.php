<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();$tripId=(int)($_GET['trip_id']??0);$date=(string)($_GET['date']??'');
try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    $service=new TripTravelOperationsService(db());if(!$service->ready())throw new RuntimeException('Run System Upgrade for Travel Day Operations.');
    $snapshot=$service->snapshot($userId,$tripId,$date?:null,true);
    echo json_encode(['ok'=>true,'operations'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Trip not found.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('Travel Operations API failed: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Travel Day Operations is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
