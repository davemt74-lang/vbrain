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
    $pdo=db();$actions=new TripAgentActionService($pdo);
    if(!$actions->ready())throw new RuntimeException('Run System Upgrade before using the Trip Agent action queue.');
    $execution=new TripAgentExecutionService($pdo);
    if($status==='accepted'){
        if(!$execution->ready())throw new RuntimeException('Run System Upgrade to enable Action Execution + Approval.');
        $execution->acceptAndStart($userId,$tripId,$actionId);flash('success','Vacation Brain assigned this Next Move to the specialist agent. You will approve the proposed trip change before it is applied.');redirect('dream-trip.php?id='.$tripId.'&tab=overview#next-moves');
    }
    if($status==='open'){
        if($execution->ready()&&$execution->executionForAction($userId,$tripId,$actionId))$execution->release($userId,$tripId,$actionId);else$actions->updateStatus($userId,$tripId,$actionId,'open');flash('success','Trip decision released back to the Next Moves queue.');redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
    }
    if(in_array($status,['dismissed','completed'],true)&&$execution->ready()){
        $current=$execution->executionForAction($userId,$tripId,$actionId);if($current&&in_array((string)($current['status']??''),['queued','agent_working','awaiting_approval','applied'],true))throw new DomainException('This Next Move is already in execution. Use Release for queued work, or approve/reject the proposal, instead of changing its lifecycle directly.');
    }
    $actions->updateStatus($userId,$tripId,$actionId,$status);
    $label=match($status){'dismissed'=>'Trip decision dismissed.','completed'=>'Trip decision completed.',default=>'Trip decision updated.'};flash('success',$label);redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
}catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){
    flash('trip_agent_error',$e->getMessage());redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
}catch(Throwable $e){
    error_log('Trip action update failed: '.$e->getMessage());flash('trip_agent_error','Vacation Brain could not update that trip decision right now.');redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($returnTab).'#next-moves');
}
