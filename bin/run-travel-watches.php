<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

$limit=25;
foreach(array_slice($argv,1) as $arg){
    if(preg_match('/^--limit=(\d+)$/',$arg,$m))$limit=max(1,min(100,(int)$m[1]));
}

try{
    $pdo=db();$service=new TravelWatchService($pdo);
    if(!$service->ready())throw new RuntimeException('Run System Upgrade before starting the travel watch worker.');
    $result=$service->runDue($limit);
    $operations=new TripTravelOperationsService($pdo);
    $result['travel_operations']=$operations->ready()?$operations->syncUpcoming($limit):['checked'=>0,'updated'=>0,'errors'=>0,'upgrade_required'=>true];
    $proactive=new ProactiveTravelService($pdo);
    $result['proactive']=$proactive->ready()?$proactive->runUpcoming($limit):['checked'=>0,'issues'=>0,'notifications'=>0,'research_started'=>0,'briefings'=>0,'errors'=>0,'upgrade_required'=>true];
    $mailbox=new BookingMailboxService($pdo);
    $result['booking_mailbox']=$mailbox->ready()?$mailbox->runDue($limit):['connections'=>0,'checked'=>0,'imported'=>0,'changes'=>0,'ignored'=>0,'errors'=>0,'upgrade_required'=>true];
    $itinerary=new TripItineraryIntelligenceService($pdo);
    $result['itinerary_intelligence']=$itinerary->ready()?$itinerary->runUpcoming($limit):['checked'=>0,'issues'=>0,'errors'=>0,'upgrade_required'=>true];
    $copilot=new TripTravelDayCopilotService($pdo);
    $result['travel_day_copilot']=$copilot->ready()?$copilot->runUpcoming($limit):['checked'=>0,'ripples'=>0,'errors'=>0,'upgrade_required'=>true];
    $recovery=new TripRecoveryIntelligenceService($pdo);
    $result['recovery_intelligence']=$recovery->ready()?$recovery->runUpcoming($limit):['checked'=>0,'incidents'=>0,'errors'=>0,'upgrade_required'=>true];
    $resolution=new TripDisruptionResolutionService($pdo);
    $result['resolution_intelligence']=$resolution->ready()?$resolution->runUpcoming($limit):['checked'=>0,'cases'=>0,'attention'=>0,'errors'=>0,'upgrade_required'=>true];
    $inboxWorker=new TripUnifiedInboxWorkerService($pdo);
    $result['trip_inbox']=$inboxWorker->ready()?$inboxWorker->runDue($limit):['users'=>0,'items'=>0,'errors'=>0,'upgrade_required'=>true];
    $errors=(int)($result['errors']??0)+(int)($result['travel_operations']['errors']??0)+(int)($result['proactive']['errors']??0)+(int)($result['booking_mailbox']['errors']??0)+(int)($result['itinerary_intelligence']['errors']??0)+(int)($result['travel_day_copilot']['errors']??0)+(int)($result['recovery_intelligence']['errors']??0)+(int)($result['resolution_intelligence']['errors']??0)+(int)($result['trip_inbox']['errors']??0);
    fwrite(STDOUT,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($errors>0?2:0);
}catch(Throwable $e){
    fwrite(STDERR,'Travel watch worker failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
