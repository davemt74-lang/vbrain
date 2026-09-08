<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';header('Content-Type: application/json; charset=utf-8');$userId=auth_user_id();if(!$userId){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'Sign in again to continue messaging.']);exit;}$service=new TravelMessageService(db());
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$action=(string)($_POST['action']??'send');$matchId=(int)($_POST['match_id']??0);
        if($action==='send'){$message=$service->send($matchId,$userId,(string)($_POST['body']??''),(int)($_POST['parent_message_id']??0));echo json_encode(['ok'=>true,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='react'){$message=$service->reactToMessage($matchId,$userId,(int)($_POST['message_id']??0),(string)($_POST['reaction']??''));echo json_encode(['ok'=>true,'message'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
        if($action==='report'){$service->reportMessage($userId,$matchId,(int)($_POST['message_id']??0),(string)($_POST['reason']??'other'),(string)($_POST['details']??''));echo json_encode(['ok'=>true,'blocked'=>true]);exit;}
        throw new InvalidArgumentException('Unknown messaging action.');
    }
    $matchId=(int)($_GET['match']??0);$after=max(0,(int)($_GET['after']??0));$messages=$service->messages($matchId,$userId,$after,100);echo json_encode(['ok'=>true,'messages'=>$messages,'unread_total'=>$service->unreadCount($userId)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
