<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$userId=require_auth();$tripId=(int)($_GET['trip_id']??0);
try{
    $snapshot=(new VacationBrainActivityService(db()))->snapshot($userId,$tripId>0?$tripId:null);
    echo json_encode(['ok'=>true,'activity'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code($e instanceof InvalidArgumentException?422:500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
