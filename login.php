<?php
require __DIR__ . '/app/bootstrap.php';
if (auth_user_id()) redirect('today.php');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $id=(new UserService(db()))->authenticate((string)($_POST['email']??''),(string)($_POST['password']??''));
    if ($id) {
        session_regenerate_id(true); $_SESSION['user_id']=$id;
        $after=$_SESSION['after_login']??null; unset($_SESSION['after_login']);
        if ($after && str_starts_with((string)$after,'/')) { header('Location: '.$after); exit; }
        if (!empty($_SESSION['pending_match_invite'])) redirect('match-invite.php?token='.urlencode((string)$_SESSION['pending_match_invite']));
        if (!empty($_SESSION['pending_match_game'])) redirect('match-game.php?token='.urlencode((string)$_SESSION['pending_match_game']));
        $role=auth_user_role();
        if ($role==='admin') redirect('admin/index.php');
        if ($role==='assessor') redirect('admin/assessments.php');
        redirect('today.php');
    }
    $error='Email or password not recognized.';
}
$title='Sign In — Vacation Brain'; require __DIR__ . '/partials/header.php';
?>
<section class="form-page"><div class="shell"><div class="form-card">
<h1>Welcome back.</h1><p class="muted">Your vacation thoughts have been unsupervised.</p>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
<div class="field"><label>Email</label><input type="email" name="email" required></div>
<div class="field"><label>Password</label><input type="password" name="password" required></div>
<button class="button primary" type="submit">Sign in</button></form>
<p class="muted small">No account yet? <a class="link-arrow" href="<?=e(app_url('diagnosis.php'))?>">Start with the diagnosis →</a></p>
</div></div></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
