<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);header('Allow: POST');exit('Method not allowed.');}
verify_csrf();

$tripId=(int)($_POST['trip_id']??0);$actionId=(int)($_POST['action_id']??0);$command=strtolower(trim((string)($_POST['command']??'')));
try{
    $pdo=db();$service=new TripAgentExecutionService($pdo);if(!$service->ready())throw new RuntimeException('Run System Upgrade to enable Action Execution + Approval.');
    if($command==='edit'){$service->editProposal($userId,$tripId,$actionId,$_POST);flash('success','Proposal updated. Review it and approve when ready.');}
    elseif($command==='approve'){
        $completed=$service->approveAndApply($userId,$tripId,$actionId,$_POST);
        if(($completed['proposal_type']??'')==='booking_handoff'){
            $bookingService=new TripBookingService($pdo);
            if($bookingService->ready()){
                $booking=$bookingService->syncApprovedHandoff($userId,$tripId,$actionId);
                if($booking){
                    $bookingActionService=new TripBookingActionService($pdo);
                    if($bookingActionService->ready())$bookingActionService->ensureHandoffIntent($userId,$tripId,(int)$booking['id'],$actionId,(int)($completed['id']??0)?:null);
                }
                flash('success','Approved. Vacation Brain saved this as Ready to Book. Confirm live availability, final price, terms, and payment with the provider. The provider transaction still requires its own locked quote and explicit approval before checkout opens.');
            }else flash('success','Approved. Run System Upgrade to add this booking handoff to Trip Readiness.');
        }else flash('success','Approved. Vacation Brain applied the proposed trip change and completed the Next Move.');
    }
    elseif($command==='reject'){$service->reject($userId,$tripId,$actionId);flash('success','Proposal rejected. Vacation Brain will not apply that change.');}
    elseif($command==='retry'){$service->retry($userId,$tripId,$actionId);flash('success','The specialist agent is retrying this Next Move.');}
    else throw new InvalidArgumentException('Unknown execution command.');
}catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){flash('trip_agent_error',$e->getMessage());}
catch(Throwable $e){error_log('Trip execution update failed: '.$e->getMessage());flash('trip_agent_error','Vacation Brain could not update this execution right now.');}
redirect('dream-trip.php?id='.$tripId.'&tab=overview#next-moves');
