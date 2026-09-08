<?php
require __DIR__ . '/app/bootstrap.php';
if (auth_user_id()) redirect('today.php');
$result = $_SESSION['diagnosis_result'] ?? null;
if (!$result) redirect('diagnosis.php');
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $userId = (new UserService(db()))->registerFromDiagnosis((string)($_POST['name']??''),(string)($_POST['email']??''),(string)($_POST['password']??''),$result);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        unset($_SESSION['diagnosis_result']);
        flash('success','Your Vacation Brain is now officially saved.');
        if (!empty($_SESSION['pending_match_invite'])) redirect('match-invite.php?token='.urlencode((string)$_SESSION['pending_match_invite']));
        if (!empty($_SESSION['pending_match_game'])) redirect('match-game.php?token='.urlencode((string)$_SESSION['pending_match_game']));
        redirect('today.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'We could not create your account.';
    }
}
$title='Save Your Vacation Brain'; require __DIR__ . '/partials/header.php';
?>
<section class="form-page"><div class="shell"><div class="form-card">
  <span class="eyebrow">Score <?= (int)$result['vacation_brain_score'] ?></span>
  <h1>Save your diagnosis.</h1>
  <p class="muted">Create an account to keep your score, build your streak, unlock achievements, and let Vacation Brain learn what kind of trip you actually want.</p>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <div class="field"><label for="name">Name</label><input id="name" name="name" maxlength="120" required value="<?= e($_POST['name'] ?? '') ?>"></div>
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="8" required><div class="muted small">8 characters minimum.</div></div>
    <button class="button primary" type="submit">Create Account →</button>
  </form>
</div></div></section>
<?php require __DIR__ . '/partials/footer.php'; ?>
