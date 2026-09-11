<?php
declare(strict_types=1);
if(!isset($pdo,$userId)||!class_exists('TravelerMemoryGraphService'))return;
try{$tmDashboardService=new TravelerMemoryGraphService($pdo);if(!$tmDashboardService->ready())return;$tmDashboard=$tmDashboardService->dashboardSummary((int)$userId);}catch(Throwable $e){return;}
$tmSignals=$tmDashboard['signals']??[];$tmRecent=$tmDashboard['recent']??null;
?>
<section class="vb-memory-summary"><div class="shell"><div class="vb-memory-summary-card">
  <div class="vb-memory-summary-copy"><span class="eyebrow">Traveler Memory</span><h2>Vacation Brain has learned from <?=(int)$tmDashboard['completed_trips']?> completed trip<?=((int)$tmDashboard['completed_trips']===1?'':'s')?>.</h2><p><?php if($tmSignals):?>Strongest learned signals: <?=e(implode(' · ',array_map(fn($s)=>(string)$s['label'].' '.((float)$s['value']>0?'+':'').number_format((float)$s['value'],1),array_slice($tmSignals,0,3))))?>.<?php else:?>Complete a Trip Memory to turn past travel into better future recommendations.<?php endif;?></p></div>
  <div class="vb-memory-summary-stats"><div><span>Trip evidence</span><strong><?=(int)$tmDashboard['completed_trips']?></strong></div><div><span>Learned signals</span><strong><?=count($tmSignals)?></strong></div><?php if($tmRecent):?><div><span>Latest memory</span><strong><?=e((string)$tmRecent['destination'])?></strong></div><?php endif;?></div>
  <a class="button primary small" href="<?=e(app_url('traveler-memory.php'))?>">Open Traveler Memory →</a>
</div></div></section>
