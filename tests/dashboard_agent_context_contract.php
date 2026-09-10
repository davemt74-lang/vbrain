<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=['assets/dashboard-agent-bar.js','assets/dashboard-agent-bar.css','api/dashboard-destination-context.php','app/Services/DashboardDestinationContextService.php','partials/footer.php','app/Services/VacationAgentService.php'];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing dashboard agent context file: {$file}\n");exit(1);}}
$js=file_get_contents($root.'/assets/dashboard-agent-bar.js')?:'';
$css=file_get_contents($root.'/assets/dashboard-agent-bar.css')?:'';
$footer=file_get_contents($root.'/partials/footer.php')?:'';
$service=file_get_contents($root.'/app/Services/DashboardDestinationContextService.php')?:'';
$agent=file_get_contents($root.'/app/Services/VacationAgentService.php')?:'';
foreach(['data-agent-plus','Compare locations','Plan a trip','Watch destinations','Research selected trips','Generate vacation photos','data-trip-select'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Dashboard agent bar missing {$needle}\n");exit(1);}}
foreach(['.vb-dashboard-agent-dock','.vb-trip-select','.vb-agent-task-modal'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Dashboard agent CSS missing {$needle}\n");exit(1);}}
if(strpos($footer,'dashboard-agent-bar.js')===false){fwrite(STDERR,"Dashboard agent bar is not loaded by footer.\n");exit(1);}
foreach(['dashboard_destination_context','is_selected','is_watching','promptContext'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Destination context service missing {$needle}\n");exit(1);}}
if(strpos($agent,'DashboardDestinationContextService')===false){fwrite(STDERR,"VacationAgentService does not consume dashboard destination context.\n");exit(1);}
echo "Dashboard agent context contract OK\n";
