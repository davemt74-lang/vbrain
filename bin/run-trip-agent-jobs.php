<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/bootstrap.php';

$limit=5;
foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--limit='))$limit=max(1,min(20,(int)substr($arg,8)));}

try{
    $pdo=db();$service=new TripAgentJobService($pdo);
    if(!$service->ready())throw new RuntimeException('Run System Upgrade before starting the trip agent worker.');
    $automation=new TripAgentAutomationService($pdo);$automationResult=$automation->ready()?$automation->runDue(min(5,$limit)):['ready'=>false,'captured'=>0,'groups'=>0,'dispatched'=>0,'deferred'=>0,'failed'=>0];
    $autonomy=new AutonomousTravelOperationsService($pdo);$autonomyStart=$autonomy->ready()?$autonomy->startEligible(max(5,$limit)):['ready'=>false,'checked'=>0,'started'=>0,'blocked'=>0,'failed'=>0];
    $executions=new TripAgentExecutionService($pdo);$executionDispatch=$executions->ready()?$executions->dispatchQueued(max(5,$limit)):['ready'=>false,'checked'=>0,'dispatched'=>0,'deferred'=>0,'failed'=>0];
    $result=$service->runDue($limit);
    $executionSync=$executions->ready()?$executions->syncFinishedJobs(max(10,$limit)):['ready'=>false,'checked'=>0,'proposed'=>0,'failed'=>0];
    $autonomyApply=$autonomy->ready()?$autonomy->applyEligible(max(10,$limit)):['ready'=>false,'checked'=>0,'applied'=>0,'blocked'=>0,'failed'=>0];
    $actions=new TripAgentActionService($pdo);$actionResult=$actions->ready()?$actions->syncCompletedOverviewBatches(max(5,$limit)):['ready'=>false,'checked'=>0,'synced'=>0,'failed'=>0,'batches'=>[]];
    $autonomyStartAfter=$autonomy->ready()?$autonomy->startEligible(max(5,$limit)):['ready'=>false,'checked'=>0,'started'=>0,'blocked'=>0,'failed'=>0];
    echo json_encode(['ok'=>true,'automation'=>$automationResult,'autonomy_start'=>$autonomyStart,'execution_dispatch'=>$executionDispatch,'execution_sync'=>$executionSync,'autonomy_apply'=>$autonomyApply,'actions'=>$actionResult,'autonomy_start_after_sync'=>$autonomyStartAfter]+$result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    $failed=(int)($result['failed']??0)+(int)($automationResult['failed']??0)+(int)($autonomyStart['failed']??0)+(int)($executionDispatch['failed']??0)+(int)($executionSync['failed']??0)+(int)($autonomyApply['failed']??0)+(int)($actionResult['failed']??0)+(int)($autonomyStartAfter['failed']??0);
    exit($failed>0?2:0);
}catch(Throwable $e){
    fwrite(STDERR,'Trip agent worker failed: '.$e->getMessage().PHP_EOL);exit(1);
}
