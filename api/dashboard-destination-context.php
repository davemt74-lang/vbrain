<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$userId=require_auth(); $service=new DashboardDestinationContextService(db());
function ctx_json(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
if($_SERVER['REQUEST_METHOD']==='GET') ctx_json(['ok'=>true,'selected'=>$service->selected($userId),'watched'=>$service->watched($userId)]);
$provided=(string)($_POST['_csrf']??''); $expected=(string)($_SESSION['_csrf']??'');
if($expected===''||!hash_equals($expected,$provided)) ctx_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
try{
 $action=(string)($_POST['action']??'');
 if($action==='select') ctx_json(['ok'=>true,'selected'=>$service->setSelected($userId,$_POST,!empty($_POST['selected']))]);
 if($action==='watch_selected') ctx_json(['ok'=>true,'watched'=>$service->setWatchingForSelected($userId,true),'selected'=>$service->selected($userId)]);
 if($action==='unwatch_selected') ctx_json(['ok'=>true,'watched'=>$service->setWatchingForSelected($userId,false),'selected'=>$service->selected($userId)]);
 throw new InvalidArgumentException('Unknown destination context action.');
}catch(Throwable $e){ctx_json(['ok'=>false,'error'=>$e->getMessage()],400);}
