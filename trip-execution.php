<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Method not allowed.');}
verify_csrf();

$tripId=(int)($_POST['trip_id']??0);$actionId=(int)($_POST['action_id']??0);$command=strtolower(trim((string)($_POST['command']??'')));
try{
    $service=new TripAgentExecutionService(db());if(!$service->ready())throw new RuntimeException('Run System Upgrade to enable Action Execution + Approval.');
    if($command==='edit'){$service->editProposal($userId,$tripId,$actionId,$_POST);flash('success','Proposal updated. Review it and approve when ready.');}
    elseif($command==='approve'){$service->approveAndApply($userId,$tripId,$actionId,$_POST);flash('success','Approved. Vacation Brain applied the proposed trip change and completed the Next Move.');}
    elseif($command==='reject'){$service->reject($userId,$tripId,$actionId);flash('success','Proposal rejected. Vacation Brain will not apply that change.');}
    elseif($command==='retry'){$service->retry($userId,$tripId,$actionId);flash('success','The specialist agent is retrying this Next Move.');}
    else throw new InvalidArgumentException('Unknown execution command.');
}catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){flash('trip_agent_error',$e->getMessage());}
catch(Throwable $e){error_log('Trip execution update failed: '.$e->getMessage());flash('trip_agent_error','Vacation Brain could not update this execution right now.');}
redirect('dream-trip.php?id='.$tripId.'&tab=overview#next-moves');