<?php
require __DIR__ . '/app/bootstrap.php';
$title = 'Vacation Brain — Self-Diagnose Your Vacation Brain';
require __DIR__ . '/partials/header.php';
?>
<section class="hero">
  <div class="shell hero-grid">
    <div>
      <span class="eyebrow">Vacation Brain Self-Diagnosis</span>
      <h1>How bad is your <em>Vacation Brain?</em></h1>
      <p class="hero-copy">You have been thinking about vacation anyway. Take the self-diagnosis, get your Vacation Brain Score, and find out just how mentally checked out you are.</p>
      <div class="cta-stack">
        <a class="button primary" href="<?= e(app_url('diagnosis.php')) ?>">Take the Self-Diagnosis Quiz →</a>
        <a class="button secondary" href="<?= e(app_url('professional-assessment.php')) ?>">Get a Qualified Professional Diagnosis</a>
      </div>
      <p class="microcopy"><?= e(diagnosis_disclaimer()) ?></p>
    </div>
    <div class="diagnosis-preview" aria-label="Sample Vacation Brain diagnosis">
      <div class="preview-card">
        <div class="preview-top">
          <div>
            <div class="score-label">Vacation Brain Score</div>
            <div class="score">742</div>
          </div>
          <span class="diagnosis-pill">Severe</span>
        </div>
        <div class="progress-track"><div class="progress-fill"></div></div>
        <p class="preview-note">At this point your employer should probably be concerned.</p>
        <div class="preview-prescription"><strong>Vacation prescription</strong>5–7 nights somewhere warm. Direct flight preferred. No scheduled activities before 10 AM.</div>
      </div>
      <div class="floating-note">Day 38 of thinking about vacation.</div>
    </div>
  </div>
</section>
<section class="section soft">
  <div class="shell">
    <h2>Ten swipes. One diagnosis.</h2>
    <p class="section-lead">The questions are funny. The profile underneath them is real. Your answers begin building the travel preferences Vacation Brain will eventually use for dream trips, local Vacation Substitutions, packages, achievements, and personalized merch.</p>
    <div class="three-grid">
      <article class="info-card"><span class="num">1</span><h3>Swipe</h3><p>Choose between ridiculous but revealing vacation scenarios.</p></article>
      <article class="info-card"><span class="num">2</span><h3>Get diagnosed</h3><p>Receive a Vacation Brain Score, personality signals, and a completely non-medical vacation prescription.</p></article>
      <article class="info-card"><span class="num">3</span><h3>Feed the brain</h3><p>Check in daily and let Vacation Brain learn what kind of escape you actually want.</p></article>
    </div>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
