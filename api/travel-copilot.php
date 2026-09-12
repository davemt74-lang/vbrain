<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();$tripId=(int)($_GET['trip_id']??$_POST['trip_id']??0);$date=(string)($_GET['date']??$_POST['date']??'');$service=new TripTravelDayCopilotService(db());
try{
    if(!$service->ready())throw new RuntimeException('Run System Upgrade for Travel Day Copilot 2.0.');
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $snapshot=$service->snapshot($userId,$tripId,$date?:null,true);
        echo json_encode(['ok'=>true,'copilot'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: GET, POST');echo json_encode(['ok'=>false,'error'=>'Method not allowed.']);exit;}
    verify_csrf();$action=(string)($_POST['action']??'');
    if($action==='item_state'){
        $service->setItemState($userId,$tripId,(string)($_POST['item_key']??''),(string)($_POST['traveler_state']??''),(int)($_POST['delay_minutes']??0),$date?:null);
    }elseif($action==='checklist'){
        $service->setChecklistCompleted($userId,$tripId,(int)($_POST['checklist_id']??0),!empty($_POST['completed']));
    }else throw new InvalidArgumentException('Unknown Copilot action.');
    $snapshot=$service->snapshot($userId,$tripId,$date?:null,true);
    echo json_encode(['ok'=>true,'copilot'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(InvalidArgumentException|DomainException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(OutOfBoundsException $e){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Trip or Copilot item not found.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
catch(Throwable $e){error_log('Travel Day Copilot API failed: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Travel Day Copilot is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
