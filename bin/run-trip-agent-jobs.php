<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/bootstrap.php';

$limit=5;
foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--limit='))$limit=max(1,min(20,(int)substr($arg,8)));}

try{
    $service=new TripAgentJobService(db());
    if(!$service->ready())throw new RuntimeException('Run System Upgrade before starting the trip agent worker.');
    $result=$service->runDue($limit);
    echo json_encode(['ok'=>true]+$result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(($result['failed']??0)>0?2:0);
}catch(Throwable $e){
    fwrite(STDERR,'Trip agent worker failed: '.$e->getMessage().PHP_EOL);exit(1);
}
