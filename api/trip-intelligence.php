<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$userId=require_auth();$tripId=(int)($_REQUEST['trip_id']??0);$service=new TripIntelligenceService(db());
try{
    if($tripId<1)throw new InvalidArgumentException('Trip is required.');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$action=(string)($_POST['action']??'refresh');
        if($action==='refresh'){
            $types=preg_split('/\s*,\s*/',(string)($_POST['types']??''),-1,PREG_SPLIT_NO_EMPTY)?:[];$data=$service->refresh($userId,$tripId,$types,true);echo json_encode(['ok'=>true,'snapshots'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
        }
        if($action==='add_item'){
            $service->addFromSnapshot($userId,$tripId,(string)($_POST['data_type']??''),(string)($_POST['external_id']??''),($_POST['scheduled_date']??'')!==''?(string)$_POST['scheduled_date']:null,($_POST['daypart']??'')!==''?(string)$_POST['daypart']:null);echo json_encode(['ok'=>true]);exit;
        }
        if($action==='schedule_item'){
            $service->scheduleItem($userId,$tripId,(int)($_POST['item_id']??0),($_POST['scheduled_date']??'')!==''?(string)$_POST['scheduled_date']:null,($_POST['daypart']??'')!==''?(string)$_POST['daypart']:null);echo json_encode(['ok'=>true]);exit;
        }
        throw new InvalidArgumentException('Unknown trip intelligence action.');
    }
    echo json_encode(['ok'=>true,'dashboard'=>$service->dashboard($userId,$tripId)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code($e instanceof InvalidArgumentException?422:500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
