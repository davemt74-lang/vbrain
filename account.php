<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$users=new UserService($pdo);$upload=new UploadService(__DIR__);$accountTypes=new AccountTypeService($pdo);$accountDefinition=$accountTypes->definition($userId);$error=null;$success=flash('success');
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'profile');
        if($action==='profile'){
            $before=$users->account($userId);
            $users->updateAccount($userId,$_POST);
            if(!empty($_FILES['avatar']['name'])){
                $url=$upload->storeImage($_FILES['avatar'],$userId,'avatars');
                if($url){$users->updateAvatar($userId,$url);$upload->deleteLocal($before['avatar_url']??null);}
            }
            flash('success','Account settings saved. Your Vacation Brain now knows who it is talking to.');redirect('account.php');
        }
        if($action==='password'){
            $new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
            if($new!==$confirm) throw new InvalidArgumentException('The new passwords do not match.');
            $users->changePassword($userId,(string)($_POST['current_password']??''),$new);
            flash('success','Password updated.');redirect('account.php');
        }
    }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'Vacation Brain could not save those settings.';}
}
$account=$users->account($userId);$title='Account & Settings';require __DIR__.'/partials/header.php';
?>
<section class="dashboard account-page"><div class="shell"><div class="dashboard-head"><div><span class="eyebrow">Account & Settings</span><h1>Your account, without the boring control panel energy.</h1><p class="muted">Manage your public identity, Vacation Brain personality, privacy preferences, password, and assigned account features.</p></div></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<div class="settings-grid">
<article class="dashboard-card"><h2>Profile</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="profile">
<div class="account-avatar-row"><div class="account-avatar"><?php if(!empty($account['avatar_url'])):?><img src="<?=e($account['avatar_url'])?>" alt="Your profile picture"><?php else:?><span><?=e(user_initials($account['display_name']??''))?></span><?php endif;?></div><div class="field grow"><label>Profile picture</label><input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"><small class="muted">JPEG, PNG, or WebP · 8 MB max.</small></div></div>
<div class="field"><label>Display name</label><input name="display_name" maxlength="120" required value="<?=e($account['display_name']??'')?>"></div>
<div class="field"><label>Username</label><input name="username" maxlength="40" value="<?=e($account['username']??'')?>" placeholder="vacationbrain"><small class="muted">Optional. Letters, numbers, dots, dashes, and underscores.</small></div>
<div class="field"><label>Email</label><input name="email" type="email" required value="<?=e($account['email']??'')?>"></div>
<div class="inline-fields"><div class="field"><label>Timezone</label><select name="timezone"><?php foreach(timezone_identifiers_list() as $tz):?><option value="<?=e($tz)?>" <?=($account['timezone']??'')===$tz?'selected':''?>><?=e($tz)?></option><?php endforeach;?></select></div><div class="field"><label>Country code</label><input name="country_code" maxlength="2" value="<?=e($account['country_code']??'')?>" placeholder="US"></div></div>
<h3>Vacation Brain personality</h3><div class="field"><label>Sarcasm level</label><select name="sarcasm_level"><?php foreach([0=>'Professional',1=>'Playful',2=>'Sarcastic',3=>'Ruthless vacation friend'] as $v=>$l):?><option value="<?=$v?>" <?=((int)($account['sarcasm_level']??2)===$v)?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></div>
<div class="field"><label>Notification personality</label><select name="notification_level"><?php foreach(['quiet'=>'Quiet','normal'=>'Normal','enthusiastic'=>'Enthusiastic'] as $v=>$l):?><option value="<?=$v?>" <?=($account['notification_level']??'normal')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div>
<label class="check-row"><input type="checkbox" name="location_enabled" value="1" <?=!empty($account['location_enabled'])?'checked':''?>> Allow location-aware Vacation Substitutions and Weather Envy</label>
<label class="check-row"><input type="checkbox" name="personalized_discovery_enabled" value="1" <?=!empty($account['personalized_discovery_enabled'])?'checked':''?>> Use my Vacation Brain profile for personalized discovery</label>
<label class="check-row"><input type="checkbox" name="merch_personalization_enabled" value="1" <?=!empty($account['merch_personalization_enabled'])?'checked':''?>> Generate merch ideas from my Vacation Brain activity</label>
<button class="button primary" type="submit">Save Account Settings</button></form></article>
<article class="dashboard-card"><div class="eyebrow">Account type</div><h2><?=e($accountDefinition['label'])?></h2><p class="muted"><?=e($accountDefinition['description'])?></p><div class="tag-row" style="display:flex;gap:6px;flex-wrap:wrap;margin:12px 0 20px"><?php foreach($accountDefinition['features'] as $feature):?><span class="badge"><?=e(ucwords(str_replace('_',' ',$feature)))?></span><?php endforeach;?></div><?php if(in_array('destination_dashboard',$accountDefinition['features'],true)):?><a class="button primary" href="<?=e(app_url('destination-dashboard.php'))?>">Open Destination Dashboard</a><?php endif;?><a class="text-link" href="<?=e(app_url('destination-claim.php'))?>">Claim a destination →</a><hr><h2>Password</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="password"><div class="field"><label>Current password</label><input type="password" name="current_password" required></div><div class="field"><label>New password</label><input type="password" name="new_password" minlength="8" required></div><div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" minlength="8" required></div><button class="button secondary" type="submit">Change Password</button></form>
<hr><h2>Vacation Yourself</h2><p class="muted">Create fictional AI vacation photos using the profile photos you explicitly select. Consent, AI-only reference photos, and your generated gallery are managed separately.</p><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="button primary" href="<?=e(app_url('vacation-yourself.php'))?>">Create Vacation Photo</a><a class="button secondary" href="<?=e(app_url('vacation-gallery.php'))?>">My Fake Vacations</a></div>
<hr><h2>Travel Matching</h2><p class="muted">Travel Matching remains opt-in. Manage enrollment, gender preferences, discovery filters, prompts, and matching photos separately.</p><a class="button secondary" href="<?=e(app_url('match-profile.php'))?>">Travel Match Profile</a><a class="text-link" href="<?=e(app_url('notification-settings.php'))?>">Notification preferences →</a></article>
</div></div></section>
<?php require __DIR__.'/partials/footer.php';?>
