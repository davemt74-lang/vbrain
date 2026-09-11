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
    $result=$service->runDue($limit);
    echo json_encode(['ok'=>true,'automation'=>$automationResult]+$result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit((($result['failed']??0)>0||($automationResult['failed']??0)>0)?2:0);
}catch(Throwable $e){
    fwrite(STDERR,'Trip agent worker failed: '.$e->getMessage().PHP_EOL);exit(1);
}
