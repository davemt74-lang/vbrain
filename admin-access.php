<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();
if(is_admin()) redirect('admin/index.php');
$ownerId=installation_owner_user_id();
$error='';
if($ownerId!==null && $ownerId!==$userId){http_response_code(403);$title='Admin Access — Vacation Brain';require __DIR__.'/partials/header.php';?>
<section class="dashboard"><div class="shell narrow"><div class="dashboard-card"><div class="eyebrow">Admin Access</div><h1>This installation already has an owner.</h1><p class="muted">Your account is signed in normally, but it has not been granted Admin access.</p><a class="button secondary" href="<?=e(app_url('today.php'))?>">Back to Vacation Brain</a></div></div></section><?php require __DIR__.'/partials/footer.php';exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $password=(string)($_POST['password']??'');
        $stmt=$pdo->prepare('SELECT password_hash FROM user_auth WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$hash=(string)$stmt->fetchColumn();
        if($hash==='' || !password_verify($password,$hash)) throw new InvalidArgumentException('Enter your current account password to claim the Admin Dashboard.');
        if(db_column_exists('user_auth','role'))$pdo->prepare('UPDATE user_auth SET role=? WHERE user_id=?')->execute(['admin',$userId]);
        if(db_table_exists('site_settings'))set_site_setting('installation.owner_user_id',(string)$userId,'system',$userId);
        flash('success','This account is now the Vacation Brain installation owner.');
        redirect('admin/index.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$title='Admin Access — Vacation Brain';require __DIR__.'/partials/header.php';?>
<section class="dashboard"><div class="shell narrow"><div class="dashboard-card owner-recovery-card"><div class="eyebrow">One-time installation owner recovery</div><h1>Claim your Admin Dashboard.</h1><p>Your existing install does not have an explicit installation owner recorded. Confirm the password for the account you are currently using and Vacation Brain will permanently assign this account the Admin role.</p><p class="muted">No extra recovery key is required. Once claimed, other users cannot use this recovery screen.</p><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><form method="post" class="stack"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><label>Current account password<input class="input" type="password" name="password" autocomplete="current-password" required></label><button class="button primary">Claim Admin Dashboard</button></form></div></div></section>
<?php require __DIR__.'/partials/footer.php';?>
