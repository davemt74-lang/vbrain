<?php
require __DIR__ . '/app/bootstrap.php';
$result = $_SESSION['diagnosis_result'] ?? null;
if (!$result) redirect('diagnosis.php');
$title = 'Your Vacation Brain Diagnosis';
$topTraits = array_slice($result['traits'] ?? [], 0, 6, true);
$share = sprintf('My Vacation Brain Score is %d — %s. Apparently I need a vacation.', $result['vacation_brain_score'], $result['title']);
require __DIR__ . '/partials/header.php';
?>
<section class="result-wrap">
  <div class="shell result-grid">
    <article class="result-card">
      <div class="diagnosis-scoreline"><span>Vacation Brain Score</span><span>•</span><span>Self-Diagnosis <?= (int)$result['diagnosis_score'] ?>/100</span></div>
      <div class="result-score"><?= (int)$result['vacation_brain_score'] ?></div>
      <h1><?= e($result['title']) ?></h1>
      <p class="section-lead" style="margin-bottom:20px"><?= e($result['summary']) ?></p>
      <span class="eyebrow">Vacation prescription</span>
      <ul class="prescription-list">
        <?php foreach ($result['prescription'] as $item): ?><li>✓ <?= e($item) ?></li><?php endforeach; ?>
      </ul>
      <div class="share-row">
        <?php if (!auth_user_id()): ?><a class="button primary" href="<?= e(app_url('signup.php')) ?>">Save My Vacation Brain →</a><?php else: ?><a class="button primary" href="<?= e(app_url('today.php')) ?>">Go to Today →</a><?php endif; ?>
        <button class="button secondary" type="button" data-copy="<?= e($share) ?>">Copy result</button>
        <a class="button secondary" href="<?= e(app_url('professional-assessment.php')) ?>">Get Professional Assessment</a>
      </div>
      <p class="microcopy"><?= e(diagnosis_disclaimer()) ?></p>
    </article>
    <aside class="side-card">
      <h2 style="margin-top:0">What your answers are already telling us</h2>
      <div class="traits">
        <?php foreach ($topTraits as $trait): ?>
          <div class="trait-row"><strong><?= e($trait['name']) ?></strong><div class="trait-meter"><span style="width:<?= (int)$trait['score'] ?>%"></span></div><span><?= (int)$trait['score'] ?>%</span></div>
        <?php endforeach; ?>
      </div>
      <hr style="border:0;border-top:1px solid var(--line);margin:24px 0">
      <p class="muted">This is the beginning of your Vacation Brain profile. Daily check-ins and future swipe decks will make the profile more confident over time.</p>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
