<?php
declare(strict_types=1);
if(!isset($userId,$id)||!class_exists('TripAffordabilityService'))return;
try{$vbAffordabilityService=new TripAffordabilityService(db());$vbAffordability=$vbAffordabilityService->snapshot((int)$userId,(int)$id);}catch(Throwable){return;}
if(empty($vbAffordability['ready'])||empty($vbAffordability['can_view'])||empty($vbAffordability['forecast']))return;
$vbForecast=$vbAffordability['forecast'];$vbCurrency=(string)($vbForecast['currency']??'USD');$vbPrefix=$vbCurrency==='USD'?'$':$vbCurrency.' ';$vbRisk=(string)($vbForecast['risk_status']??'unknown');$vbRiskLabel=ucwords(str_replace('_',' ',$vbRisk));$vbExpected=(float)($vbForecast['expected_final']??0);$vbTarget=$vbForecast['target_budget']??null;$vbHeadroom=$vbForecast['headroom']??null;
?>
<link rel="stylesheet" href="<?=e(app_url('assets/trip-affordability.css'))?>">
<section class="dashboard-card vb-affordability-summary" data-affordability-risk="<?=e($vbRisk)?>">
    <div><span class="eyebrow">Affordability forecast</span><h2><?=e($vbRiskLabel)?></h2><p><?=e($vbPrefix.number_format($vbExpected,0))?> expected final cost<?php if($vbTarget!==null):?> against a <?=e($vbPrefix.number_format((float)$vbTarget,0))?> target<?php endif;?>. <span><?=e(ucfirst((string)($vbForecast['confidence']??'low')))?> confidence.</span></p></div>
    <div class="vb-affordability-summary-meta"><?php if($vbHeadroom!==null):?><strong><?=e($vbPrefix.number_format(abs((float)$vbHeadroom),0))?></strong><small><?=$vbHeadroom>=0?'forecast headroom':'over target'?></small><?php else:?><strong>Set a budget</strong><small>to score affordability</small><?php endif;?></div>
    <a class="button secondary small" href="<?=e(app_url('trip-affordability.php?id='.(int)$id))?>">Open affordability</a>
</section>
