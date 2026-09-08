<?php
require __DIR__ . '/app/bootstrap.php';
$service = new DiagnosisService(db());
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $answers = json_decode((string)($_POST['answers_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $result = $service->calculate(is_array($answers) ? $answers : []);
        $_SESSION['diagnosis_result'] = $result;
        redirect('diagnosis-result.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'We could not calculate your diagnosis. Please try again.';
    }
}
$questions = $service->questions();
$title = 'Vacation Brain Self-Diagnosis';
require __DIR__ . '/partials/header.php';
?>
<section class="quiz-shell">
  <div class="shell quiz-frame">
    <div class="quiz-header">
      <div><strong>Vacation Brain Self-Diagnosis</strong><div class="muted small">Answer quickly. Overthinking is itself a symptom.</div></div>
      <div class="quiz-progress" data-progress-label>1 of <?= count($questions) ?></div>
    </div>
    <div class="bar"><span data-progress-bar style="width:0"></span></div>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" data-quiz style="margin-top:18px">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="answers_json" value="[]">
      <?php foreach ($questions as $i => $question): ?>
        <article class="quiz-card <?= $i===0?'active':'' ?>" data-question="<?= (int)$question['id'] ?>">
          <div class="kicker">Diagnosis card <?= $i+1 ?></div>
          <h1><?= e($question['body']) ?></h1>
          <?php if ($question['short_body']): ?><div class="sub"><?= e($question['short_body']) ?></div><?php endif; ?>
          <div class="choice-grid">
            <?php foreach ($question['choices'] as $choice): ?>
              <button class="choice" type="button" data-choice="<?= (int)$choice['id'] ?>"><?= e($choice['label']) ?></button>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </form>
    <p class="microcopy center"><?= e(diagnosis_disclaimer()) ?></p>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
