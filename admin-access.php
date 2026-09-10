<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();

if(is_admin()) redirect('admin/index.php');

$ownerId=installation_owner_user_id();
$error='';

// Admin recovery is only a one-time bootstrap path for the first account on
// installations that do not yet have an owner recorded. An existing owner
// marker never grants Admin permission by itself; user_auth.role is authoritative.
if($ownerId!==null || !is_primary_install_user()){
    http_response_code(403);
    $title='Admin Access — Vacation Brain';
    require __DIR__.'/partials/header.php';?>
<section class="dashboard"><div class="shell narrow"><div class="dashboard-card">
  <div class="eyebrow">Admin Access</div>
  <h1>Admin access is restricted.</h1>
  <p class="muted">Only an account whose current role is Admin can open the Admin Dashboard. The one-time setup flow is available only to the first account on a new or legacy installation before an installation owner has been recorded.</p>
  <a class="button secondary" href="<?=e(app_url('today.php'))?>">Back to Vacation Brain</a>
</div></div></section>
<?php require __DIR__.'/partials/footer.php';exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        // Re-check eligibility at write time so a stale page cannot claim Admin
        // after another account completed setup.
        if(installation_owner_user_id()!==null || !is_primary_install_user()){
            throw new RuntimeException('Admin setup is no longer available for this account.');
        }
        $password=(string)($_POST['password']??'');
        $stmt=$pdo->prepare('SELECT password_hash FROM user_auth WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        $hash=(string)$stmt->fetchColumn();
        if($hash==='' || !password_verify($password,$hash)){
            throw new InvalidArgumentException('Enter the current password for the first account to complete Admin setup.');
        }
        if(db_column_exists('user_auth','role')){
            $pdo->prepare('UPDATE user_auth SET role=? WHERE user_id=?')->execute(['admin',$userId]);
        }
        if(db_table_exists('site_settings')){
            set_site_setting('installation.owner_user_id',(string)$userId,'system',$userId);
        }
        flash('success','Admin setup completed for the first account.');
        redirect('admin/index.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$title='Admin Setup — Vacation Brain';
require __DIR__.'/partials/header.php';?>
<section class="dashboard"><div class="shell narrow"><div class="dashboard-card owner-recovery-card">
  <div class="eyebrow">One-time first-account setup</div>
  <h1>Set up Admin access.</h1>
  <p>This installation does not have an owner recorded yet. Only the first Vacation Brain account can complete this one-time Admin setup.</p>
  <p class="muted">Confirm that first account's existing password. After setup, Admin access is controlled only by the account role stored in Vacation Brain.</p>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <form method="post" class="stack">
    <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
    <label>First account password<input class="input" type="password" name="password" autocomplete="current-password" required></label>
    <button class="button primary">Complete Admin Setup</button>
  </form>
</div></div></section>
<?php require __DIR__.'/partials/footer.php';?>
