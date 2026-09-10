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
        if ($userId=auth_user_id()) {
            $result['result_id']=(new DiagnosisPersistenceService(db()))->record($userId,$result);
            $_SESSION['diagnosis_result']=$result;
        }
        redirect('diagnosis-result.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'We could not calculate your diagnosis. Please try again.';
    }
}
$questions = $service->questions();
$minAnswers = DiagnosisService::MIN_ANSWERS;
$title = 'Vacation Brain Self-Diagnosis';
require __DIR__ . '/partials/header.php';
?>
<section class="quiz-shell">
  <div class="shell quiz-frame">
    <div class="quiz-header">
      <div>
        <strong>Vacation Brain Self-Diagnosis</strong>
        <div class="muted small">Ten answers unlock your diagnosis. Keep going afterward for a sharper read.</div>
      </div>
      <div class="quiz-progress" data-diagnosis-progress-label>1 of <?= min($minAnswers, count($questions)) ?> required</div>
    </div>
    <div class="bar"><span data-diagnosis-progress-bar style="width:0"></span></div>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" data-diagnosis-quiz data-min-answers="<?= (int)$minAnswers ?>" style="margin-top:18px">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="answers_json" value="[]">

      <?php foreach ($questions as $i => $question): ?>
        <article class="quiz-card <?= $i===0?'active':'' ?>" data-question="<?= (int)$question['id'] ?>" data-question-index="<?= $i ?>">
          <div class="kicker">Diagnosis card <?= $i+1 ?><?= $i >= $minAnswers ? ' · optional depth' : '' ?></div>
          <h1><?= e($question['body']) ?></h1>
          <?php if ($question['short_body']): ?><div class="sub"><?= e($question['short_body']) ?></div><?php endif; ?>
          <div class="choice-grid">
            <?php foreach ($question['choices'] as $choice): ?>
              <button class="choice" type="button" data-diagnosis-choice="<?= (int)$choice['id'] ?>"><?= e($choice['label']) ?></button>
            <?php endforeach; ?>
          </div>
          <?php if ($i >= $minAnswers): ?>
            <div style="margin-top:18px;text-align:center">
              <button class="button secondary small" type="button" data-diagnosis-submit-now>Use my current answers</button>
              <div class="microcopy" style="margin-top:7px">Every extra answer improves the diagnosis profile.</div>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>

      <?php if (count($questions) > $minAnswers): ?>
        <article class="dashboard-card" data-diagnosis-checkpoint hidden style="max-width:760px;margin:0 auto;text-align:center;padding:34px">
          <div class="kicker">Diagnosis unlocked</div>
          <h1 style="margin-bottom:10px">We have enough to diagnose your Vacation Brain.</h1>
          <p class="muted" style="max-width:580px;margin:0 auto 24px">You can get your result now, or answer <?= max(0, count($questions)-$minAnswers) ?> optional questions for a more detailed travel profile and stronger recommendations.</p>
          <div style="display:flex;justify-content:center;gap:12px;flex-wrap:wrap">
            <button class="button primary" type="button" data-diagnosis-submit>Get my diagnosis</button>
            <button class="button secondary" type="button" data-diagnosis-continue>Keep going →</button>
          </div>
        </article>
      <?php endif; ?>
    </form>

    <p class="microcopy center"><?= e(diagnosis_disclaimer()) ?></p>
  </div>
</section>

<script>
(() => {
  const quiz = document.querySelector('[data-diagnosis-quiz]');
  if (!quiz) return;

  const cards = [...quiz.querySelectorAll('.quiz-card')];
  const answerInput = quiz.querySelector('input[name="answers_json"]');
  const bar = document.querySelector('[data-diagnosis-progress-bar]');
  const label = document.querySelector('[data-diagnosis-progress-label]');
  const checkpoint = quiz.querySelector('[data-diagnosis-checkpoint]');
  const minAnswers = Math.min(Number(quiz.dataset.minAnswers || 10), cards.length);
  const answers = {};
  let index = 0;
  let passedCheckpoint = cards.length <= minAnswers;

  const answeredCount = () => Object.keys(answers).length;
  const syncAnswers = () => {
    answerInput.value = JSON.stringify(Object.values(answers));
  };

  const render = () => {
    if (checkpoint) checkpoint.hidden = true;
    cards.forEach((card, i) => card.classList.toggle('active', i === index));
    const answered = answeredCount();
    const pct = cards.length ? Math.round((answered / cards.length) * 100) : 0;
    if (bar) bar.style.width = `${pct}%`;
    if (label) {
      if (answered < minAnswers) {
        label.textContent = `${Math.min(index + 1, minAnswers)} of ${minAnswers} required`;
      } else {
        label.textContent = `${answered} answered · ${Math.max(0, cards.length - answered)} optional left`;
      }
    }
  };

  const showCheckpoint = () => {
    if (!checkpoint) return;
    cards.forEach(card => card.classList.remove('active'));
    checkpoint.hidden = false;
    const answered = answeredCount();
    if (bar) bar.style.width = `${Math.round((answered / cards.length) * 100)}%`;
    if (label) label.textContent = `${answered} answered · diagnosis ready`;
    checkpoint.scrollIntoView({behavior:'smooth', block:'center'});
  };

  cards.forEach((card, cardIndex) => {
    card.querySelectorAll('[data-diagnosis-choice]').forEach(button => {
      button.addEventListener('click', () => {
        card.querySelectorAll('[data-diagnosis-choice]').forEach(b => b.classList.remove('selected'));
        button.classList.add('selected');
        answers[card.dataset.question] = Number(button.dataset.diagnosisChoice);
        syncAnswers();

        window.setTimeout(() => {
          if (!passedCheckpoint && answeredCount() === minAnswers && cardIndex === minAnswers - 1) {
            showCheckpoint();
            return;
          }
          if (cardIndex < cards.length - 1) {
            index = cardIndex + 1;
            render();
          } else {
            quiz.requestSubmit();
          }
        }, 150);
      });
    });
  });

  checkpoint?.querySelector('[data-diagnosis-submit]')?.addEventListener('click', () => quiz.requestSubmit());
  checkpoint?.querySelector('[data-diagnosis-continue]')?.addEventListener('click', () => {
    passedCheckpoint = true;
    index = minAnswers;
    render();
  });
  quiz.querySelectorAll('[data-diagnosis-submit-now]').forEach(button => {
    button.addEventListener('click', () => quiz.requestSubmit());
  });

  render();
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
