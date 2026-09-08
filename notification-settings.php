<?php
require __DIR__.'/app/bootstrap.php';$userId=require_auth();$service=new NotificationService(db());$success=flash('success');
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$service->savePreferences($userId,$_POST);flash('success','Notification preferences saved. Vacation Brain will try to be appropriately annoying.');redirect('notification-settings.php');}
$p=$service->preferences($userId);$title='Notification Settings';require __DIR__.'/partials/header.php';
$rows=[
'new_matches'=>['New Travel Matches','Tell me when a mutual like becomes a match.'],
'new_messages'=>['New messages','Private Travel Match messages.'],
'profile_reactions'=>['Reactions','Profile-answer and message reactions.'],
'daily_match_question'=>['Daily Match Question','Shared ridiculous question reminders.'],
'match_discoveries'=>['Match discoveries','New things Vacation Brain notices about a match.'],
'streak_reminders'=>['Match streak reminders','Keep a shared interaction streak alive.'],
'daily_checkin'=>['Vacation Brain daily check-in','Remind me to confirm I am still thinking about vacation.'],
'achievements_merch'=>['Achievements & merch unlocks','Badges and things your behavior deserves to become a shirt.'],
];
?>
<section class="match-page"><div class="shell narrow"><div class="section-head"><div><span class="eyebrow">Alerts</span><h1>Notification preferences</h1><p class="muted">Choose which forms of Vacation Brain interference are welcome.</p></div><a href="<?=e(app_url('notifications.php'))?>">← Alerts</a></div><?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><form method="post" class="notification-settings-card"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><?php foreach($rows as $key=>$copy):?><label class="notification-setting"><input type="checkbox" name="<?=e($key)?>" value="1" <?=!empty($p[$key])?'checked':''?>><span><strong><?=e($copy[0])?></strong><small><?=e($copy[1])?></small></span></label><?php endforeach;?><button class="button primary" type="submit">Save Preferences</button></form></div></section>
<?php require __DIR__.'/partials/footer.php';?>
