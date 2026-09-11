<?php
declare(strict_types=1);

$root=dirname(__DIR__);$files=['app/Services/TripCommandCenterService.php','api/trip-command-center.php','partials/trip-command-center.php','assets/trip-command-center.css','assets/trip-command-center.js','partials/footer.php','app/bootstrap.php'];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Trip Command Center file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$service=$read('app/Services/TripCommandCenterService.php');
foreach(['class TripCommandCenterService','function snapshot','function upcomingTrips','function activeAgents','function watchAlerts','function activeActions','function executions','function bookingHandoffs','function risks','function attention',"status IN ('queued','running')","status='awaiting_approval'","proposal_type='booking_handoff'","DATE_SUB(NOW(),INTERVAL 7 DAY)",'budget_percent','watch_alerts_24h','approvals_waiting','open_next_moves','needs_you','db_table_exists','user_id=?'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Command Center service missing {$needle}\n");exit(1);}}
foreach(['Flights Agent','Weather Agent','Budget Agent','dream-trip.php?id=','watches.php'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Command Center navigation/agent scope missing {$needle}\n");exit(1);}}

$api=$read('api/trip-command-center.php');foreach(['require_auth','Cache-Control: private, no-store','TripCommandCenterService','command_center','JSON_INVALID_UTF8_SUBSTITUTE','Trip Command Center is temporarily unavailable'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Command Center API missing {$needle}\n");exit(1);}}

$ui=$read('partials/trip-command-center.php');foreach(['data-vb-command-center','Trip Command Center','Needs you today','Trips in motion','Agent operations','Weather + budget risks','Booking handoffs','Recent watch alerts','Approval required','external bookings require live provider confirmation','data-command-attention','data-command-trips','data-command-agents','data-command-risks','data-command-handoffs','data-command-alerts'] as $needle){if(strpos($ui,$needle)===false){fwrite(STDERR,"Command Center UI missing {$needle}\n");exit(1);}}

$js=$read('assets/trip-command-center.js');foreach(['data-vb-command-center','data-vb-command-config','.vb-local-section','insertAdjacentElement(\'afterend\',root)','active_executions','15000','60000','data-vb-command-refresh','credentials:\'same-origin\''] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Command Center live client missing {$needle}\n");exit(1);}}if(strpos($js,'Math.random')!==false){fwrite(STDERR,"Command Center must reflect real data, not random state.\n");exit(1);}

$css=$read('assets/trip-command-center.css');foreach(['vb-command-center','vb-command-stats','vb-command-grid','vb-command-attention','vb-command-trip','vb-command-agent-state','vb-risk-level','vb-command-handoff-row','@media(max-width:680px)'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Command Center styling missing {$needle}\n");exit(1);}}

$footer=$read('partials/footer.php');foreach(['trip-command-center.php','trip-command-center.css','data-vb-command-config','api/trip-command-center.php','trip-command-center.js'] as $needle){if(strpos($footer,$needle)===false){fwrite(STDERR,"Command Center Today mount missing {$needle}\n");exit(1);}}$brainPos=strpos($footer,"assets/brain-activity.js");$commandPos=strpos($footer,"assets/trip-command-center.js");if($brainPos===false||$commandPos===false||$commandPos<$brainPos){fwrite(STDERR,"Command Center client must load after Brain Activity so it can keep Local Day Trips first and place Command Center before EEG.\n");exit(1);}

$bootstrap=$read('app/bootstrap.php');if(strpos($bootstrap,"/Services/TripCommandCenterService.php")===false){fwrite(STDERR,"TripCommandCenterService is not loaded.\n");exit(1);}echo "Trip Command Center contract OK\n";
