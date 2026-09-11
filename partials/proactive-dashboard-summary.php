<?php
if(!isset($userId))return;
try{$vbProDashService=new ProactiveTravelService(db());if(!$vbProDashService->ready())return;$vbProDash=$vbProDashService->dashboardSummary((int)$userId,6);}catch(Throwable $e){error_log('Proactive dashboard summary failed: '.$e->getMessage());return;}
$vbProDashIssues=(array)($vbProDash['issues']??[]);$vbProDashBriefings=(array)($vbProDash['briefings']??[]);if(!$vbProDashIssues&&!$vbProDashBriefings)return;
?>
<section class="dashboard vb-proactive-summary"><div class="shell"><div class="dashboard-card">
<div class="vb-proactive-summary-head"><div><span class="eyebrow">Proactive Vacation Brain</span><h2>Trips that need your attention</h2><p class="muted">Meaningful risks, conflicts and opportunities from your saved live trip data.</p></div><div><strong><?=count($vbProDashIssues)?></strong> open · <span class="muted"><?=(int)($vbProDash['critical']??0)?> critical / <?=(int)($vbProDash['high']??0)?> high</span></div></div>
<?php if($vbProDashIssues):?><div class="vb-proactive-summary-grid"><?php foreach($vbProDashIssues as $issue):?><a class="vb-proactive-summary-item" href="<?=e((string)$issue['url'])?>"><span class="vb-proactive-badge <?=e((string)$issue['severity'])?>"><?=e(ucfirst((string)$issue['severity']))?></span><strong><?=e((string)$issue['title'])?></strong><p><?=e((string)$issue['trip_name'])?> · <?=e(ucfirst((string)$issue['source_state']))?> · <?=(int)$issue['confidence']?>% confidence</p></a><?php endforeach;?></div><?php endif;?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px"><a class="button secondary small" href="<?=e(app_url('proactive-settings.php'))?>">Proactive settings</a><?php if($vbProDashBriefings):$b=$vbProDashBriefings[0];?><a class="button secondary small" href="<?=e(app_url('proactive-trip.php?id='.(int)$b['dream_trip_id'].'#briefings'))?>">Latest briefing</a><?php endif;?></div>
</div></div></section>
