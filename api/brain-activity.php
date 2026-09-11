<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

$userId=require_auth();
$tripId=(int)($_GET['trip_id']??0);

function vb_apply_agent_jobs_to_activity(array $activity,array $jobs): array
{
    $activity['jobs_available']=!empty($jobs['ready']);
    $activity['active_agent_jobs']=(int)($jobs['active_total']??0);
    $byAgent=is_array($jobs['by_agent']??null)?$jobs['by_agent']:[];
    $hasRunning=false;$hasQueued=false;
    foreach($activity['channels']??[] as &$channel){
        $key=(string)($channel['key']??'overview');$state=is_array($byAgent[$key]??null)?$byAgent[$key]:[];
        $running=(int)($state['running']??0);$queued=(int)($state['queued']??0);$completed=(int)($state['completed']??0);$failed=(int)($state['failed']??0);
        $channel['metrics']=is_array($channel['metrics']??null)?$channel['metrics']:[];
        $channel['metrics']['running_jobs']=$running;$channel['metrics']['queued_jobs']=$queued;$channel['metrics']['completed_jobs_24h']=$completed;$channel['metrics']['failed_jobs_24h']=$failed;
        $channel['job_state']=$running>0?'running':($queued>0?'queued':(string)($state['last_status']??'idle'));
        $channel['job_progress']=$running>0||$queued>0?(int)($state['last_progress']??0):null;
        $channel['job_id']=$running>0||$queued>0?(int)($state['job_id']??0):null;
        if($running>0){
            $hasRunning=true;$channel['intensity']=max(92,(int)($channel['intensity']??0));$channel['state']='working';$channel['reason']='Agent is working now: '.trim((string)($state['last_progress']??0)).'% through the queued task.';
            if(is_array($channel['series']??null)&&$channel['series'])$channel['series'][count($channel['series'])-1]=max(95,(int)end($channel['series']));
        }elseif($queued>0){
            $hasQueued=true;$channel['intensity']=max(58,(int)($channel['intensity']??0));if(($channel['state']??'idle')==='idle')$channel['state']='active';$channel['reason']='Agent task is queued and waiting for the background worker.';
            if(is_array($channel['series']??null)&&$channel['series'])$channel['series'][count($channel['series'])-1]=max(58,(int)end($channel['series']));
        }
    }
    unset($channel);
    if(($activity['series']??[])&&($hasRunning||$hasQueued)){
        $last=count($activity['series'])-1;$activity['series'][$last]=max($hasRunning?88:55,(int)$activity['series'][$last]);
    }
    if($hasRunning){$activity['score']=max(78,(int)($activity['score']??0));$activity['status']='Agents Working';}
    elseif($hasQueued){$activity['score']=max(52,(int)($activity['score']??0));$activity['status']='Agents Queued';}
    return $activity;
}

try{
    $pdo=db();$scopeTrip=$tripId>0?$tripId:null;
    $snapshot=(new VacationBrainActivityService($pdo))->snapshot($userId,$scopeTrip);
    $jobService=new TripAgentJobService($pdo);$jobs=$jobService->activityStates($userId,$scopeTrip);
    $snapshot=vb_apply_agent_jobs_to_activity($snapshot,$jobs);
    echo json_encode(['ok'=>true,'activity'=>$snapshot],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(OutOfBoundsException $e){
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Brain activity is temporarily unavailable.'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
