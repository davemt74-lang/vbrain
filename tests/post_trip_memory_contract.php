<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=['db/044_post_trip_memory.sql','app/Services/TripMemoryService.php','trip-memory.php','assets/trip-memory.css','app/bootstrap.php','app/Services/DestinationRecommendationService.php'];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Post-Trip Memory file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';
$m=$read('db/044_post_trip_memory.sql');foreach(['trip_memories','trip_memory_signals','trip_memory_items','learning_enabled','private_notes','actual_spend',"'app_version','1.36'"] as $n){if(strpos($m,$n)===false){fwrite(STDERR,"Post-trip migration missing {$n}\n");exit(1);}}
$s=$read('app/Services/TripMemoryService.php');foreach(['class TripMemoryService','function snapshot','function save','function learningSignals','function augmentTraits','function rerankDestinations','function learningSummary','function agentContext','private trip notes are excluded','m.learning_enabled=1','m.status="complete"'] as $n){if(strpos($s,$n)===false){fwrite(STDERR,"Trip Memory service missing {$n}\n");exit(1);}}
foreach(['user_id=? AND dream_trip_id=?','overall trip rating','Ratings must be from 1 to 5','Trip Memory opens after the trip is completed'] as $n){if(strpos($s,$n)===false){fwrite(STDERR,"Trip Memory ownership/validation missing {$n}\n");exit(1);}}
$page=$read('trip-memory.php');foreach(['require_auth','verify_csrf()','Post-trip intelligence','Preference learning','Actual vs. planned','Private notes','learning_enabled','Complete Trip Memory','migration 044 / app v1.36'] as $n){if(strpos($page,$n)===false){fwrite(STDERR,"Trip Memory page missing {$n}\n");exit(1);}}
$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripMemoryService.php")===false){fwrite(STDERR,"TripMemoryService is not loaded.\n");exit(1);}
$rec=$read('app/Services/DestinationRecommendationService.php');foreach(['TripMemoryService','augmentTraits','rerankDestinations'] as $n){if(strpos($rec,$n)===false){fwrite(STDERR,"Destination recommendations do not use Trip Memory {$n}.\n");exit(1);}}
if(strpos($rec,'private_notes')!==false){fwrite(STDERR,"Destination recommendation path must not consume private Trip Memory notes.\n");exit(1);}
echo "Post-Trip Memory contract OK\n";
