<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();$service=new TripAgentJobService(db());
try{
    if(!$service->ready()){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Run System Upgrade before using live agent jobs.']);exit;}
    $tripId=(int)($_REQUEST['trip_id']??0);if($tripId<1)throw new InvalidArgumentException('Trip is required.');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        echo json_encode(['ok'=>true,'jobs'=>$service->jobsForTrip($userId,$tripId,50),'activity'=>$service->activityStates($userId,$tripId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('Unsupported request method.');
    verify_csrf();$action=(string)($_POST['action']??'enqueue');
    if($action==='enqueue'){$job=$service->enqueue($userId,$tripId,(string)($_POST['agent_type']??'overview'),(string)($_POST['message']??''));echo json_encode(['ok'=>true,'job'=>$job],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='run_all'){$jobs=$service->enqueueAll($userId,$tripId);echo json_encode(['ok'=>true,'jobs'=>$jobs],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    if($action==='cancel'){$job=$service->cancel($userId,(int)($_POST['job_id']??0));echo json_encode(['ok'=>true,'job'=>$job],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
    throw new InvalidArgumentException('Unknown agent job action.');
}catch(OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(DomainException $e){http_response_code(409);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Agent jobs are temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
