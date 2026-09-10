<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();
if($_SERVER['REQUEST_METHOD']!=='POST')redirect('dream.php');
verify_csrf();
$tripId=(int)($_POST['trip_id']??0);$agentType=(string)($_POST['agent_type']??'overview');$message=trim((string)($_POST['message']??''));
try{(new TripAgentService(db()))->send($userId,$tripId,$agentType,$message);flash('trip_agent_success','Trip agent updated.');}
catch(Throwable $e){flash('trip_agent_error',$e->getMessage());}
$allowed=['overview','weather','flights','events','local','itinerary','budget'];if(!in_array($agentType,$allowed,true))$agentType='overview';
redirect('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($agentType).'#agent-results');
