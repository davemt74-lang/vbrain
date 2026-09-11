<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$service=new ProactiveTravelService(db());$error='';$success=flash('success');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();try{$service->savePreferences($userId,$_POST);flash('success','Proactive Vacation Brain settings saved.');redirect('proactive-settings.php');}catch(Throwable $e){$error=$e->getMessage();}
}
$p=$service->preferences($userId);$title='Proactive Vacation Brain Settings';$pageStyles=['assets/proactive-travel.css'];require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-proactive-settings"><div class="shell narrow">
<div class="dashboard-head"><div><span class="eyebrow">Proactive Vacation Brain</span><h1>How much should Vacation Brain interfere?</h1><p class="muted">Control trip-risk alerts, opportunity alerts, automatic specialist research, morning briefings, and quiet hours. Automatic research can prepare options; changing a trip still requires your approval.</p></div><a class="button secondary small" href="<?=e(app_url('notifications.php'))?>">Alerts</a></div>
<?php if(!$service->ready()):?><div class="alert warning"><strong>Migration 047 / app v1.39 is required.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade →</a><?php endif;?></div><?php endif;?>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<form method="post" class="dashboard-card vb-proactive-settings-card"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
<label class="vb-proactive-setting"><input type="checkbox" name="proactive_enabled" value="1" <?=!empty($p['proactive_enabled'])?'checked':''?>><span><strong>Proactive trip monitoring</strong><small>Evaluate saved live trip data, readiness, itinerary timing, bookings, budget and traveler preferences for meaningful risks and opportunities.</small></span></label>
<label class="vb-proactive-setting"><input type="checkbox" name="urgent_alerts" value="1" <?=!empty($p['urgent_alerts'])?'checked':''?>><span><strong>Risk & conflict notifications</strong><small>Send alerts when a new issue meets your severity threshold. Quiet hours suppress the notification, not the underlying issue.</small></span></label>
<label class="vb-proactive-setting"><input type="checkbox" name="opportunity_alerts" value="1" <?=!empty($p['opportunity_alerts'])?'checked':''?>><span><strong>Opportunity alerts</strong><small>Surface useful fare/lodging drops, new events and unusually good weather windows without treating them as guaranteed availability.</small></span></label>
<label class="vb-proactive-setting"><input type="checkbox" name="auto_research" value="1" <?=!empty($p['auto_research'])?'checked':''?>><span><strong>Automatically research high-priority recovery options</strong><small>Vacation Brain may start a specialist agent when a high/critical issue appears. The agent can prepare a proposal, but applying itinerary, reservation, purchase or booking changes still requires explicit approval.</small></span></label>
<label class="vb-proactive-setting"><input type="checkbox" name="morning_briefing" value="1" <?=!empty($p['morning_briefing'])?'checked':''?>><span><strong>Morning / travel-day briefing</strong><small>Create one concise daily operational summary when an upcoming trip is close or currently traveling.</small></span></label>
<div class="vb-proactive-settings-grid"><label>Minimum risk severity<select class="input" name="minimum_severity"><?php foreach(['low'=>'Low — tell me more','medium'=>'Medium — important changes','high'=>'High — only serious issues'] as $value=>$label):?><option value="<?=e($value)?>" <?=($p['minimum_severity']??'medium')===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label>Quiet hours start<input class="input" type="time" name="quiet_start" value="<?=e(substr((string)($p['quiet_start']??''),0,5))?>"></label><label>Quiet hours end<input class="input" type="time" name="quiet_end" value="<?=e(substr((string)($p['quiet_end']??''),0,5))?>"></label></div>
<div class="vb-proactive-privacy"><strong>Privacy boundary</strong><p>Proactive Vacation Brain uses saved provider snapshots, public flight identifiers, safe reservation timing/status fields, itinerary items, budget totals and non-private traveler-learning signals. Booking confirmation codes, booking notes, payment data and private Trip Memory notes are excluded.</p></div>
<button class="button primary" type="submit">Save proactive settings</button>
</form>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
