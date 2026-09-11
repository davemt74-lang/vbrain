<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Method not allowed.');}
verify_csrf();

$tripId=(int)($_POST['trip_id']??0);
$actionId=(int)($_POST['action_id']??0);
$status=strtolower(trim((string)($_POST['status']??'')));
$returnTab=strtolower(trim((string)($_POST['return_tab']??'overview')));
if(!in_array($returnTab,['overview','weather','flights','events','local','itinerary','budget'],true))$returnTab='overview';

try{
    $service=new TripAgentActionService(db());
    if(!$service->ready())throw new RuntimeException('Run System Upgrade before using the Trip Agent action queue.');
    $updated=$service->updateStatus($userId,$tripId,$actionId,$status);
    $label=match($status){'accepted'=>'Added to your active trip decisions.','dismissed'=>'Trip decision dismissed.','completed'=>'Trip decision completed.','open'=>'Trip decision reopened.',default=>'Trip decision updated.'};
    flash('success',$label);
    $target=$returnTab;$fragment='next-moves';
    if($status==='accepted'&&!empty($updated['target_tab'])){$target=(string)$updated['target_tab'];$fragment='agent-results';}
    redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($target).'#'.$fragment);
}catch(InvalidArgumentException|OutOfBoundsException $e){
    flash('trip_agent_error',$e->getMessage());
    redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
}catch(Throwable $e){
    error_log('Trip action update failed: '.$e->getMessage());
    flash('trip_agent_error','Vacation Brain could not update that trip decision right now.');
    redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
}