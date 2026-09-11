<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$userId=require_auth();$service=new TravelWatchService(db());
try{
    if(!$service->ready())throw new RuntimeException('Run System Upgrade before using Destination Watches.');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $keys=[];$destinations=[];$trips=[];
        foreach($service->watchedKeys($userId) as $key=>$row){
            $keys[$key]=['watch_id'=>(int)$row['id'],'target_type'=>(string)$row['target_type'],'dream_trip_id'=>(int)($row['dream_trip_id']??0),'destination_catalog_id'=>(int)($row['destination_catalog_id']??0)];
        }
        foreach($service->listForUser($userId) as $row){
            if(empty($row['is_active']))continue;
            if(($row['target_type']??'')==='destination')$destinations[]=['watch_id'=>(int)$row['id'],'target_key'=>(string)$row['target_key'],'destination_catalog_id'=>(int)($row['destination_catalog_id']??0),'destination_name'=>(string)$row['destination_name']];
            elseif(($row['target_type']??'')==='trip')$trips[]=['watch_id'=>(int)$row['id'],'target_key'=>(string)$row['target_key'],'dream_trip_id'=>(int)($row['dream_trip_id']??0),'destination_name'=>(string)$row['destination_name']];
        }
        echo json_encode(['ok'=>true,'keys'=>$keys,'destinations'=>$destinations,'trips'=>$trips],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('Unsupported request method.');
    verify_csrf();$action=(string)($_POST['action']??'');
    if($action==='toggle_destination'){
        $result=$service->toggleDestination($userId,(int)($_POST['destination_catalog_id']??0),(string)($_POST['destination_name']??''),$_POST['latitude']??null,$_POST['longitude']??null);
        echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_SLASHES);exit;
    }
    if($action==='save_trip'){
        $watch=$service->saveTrip($userId,(int)($_POST['trip_id']??0),$_POST);echo json_encode(['ok'=>true,'watch'=>$watch],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='update'){
        $watch=$service->updateWatch($userId,(int)($_POST['watch_id']??0),$_POST);echo json_encode(['ok'=>true,'watch'=>$watch],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($action==='delete'){
        $service->deleteWatch($userId,(int)($_POST['watch_id']??0));echo json_encode(['ok'=>true]);exit;
    }
    throw new InvalidArgumentException('Unknown travel watch action.');
}catch(Throwable $e){
    http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
