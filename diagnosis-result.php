<?php
require __DIR__ . '/app/bootstrap.php';
$result = $_SESSION['diagnosis_result'] ?? null;
if (!$result) redirect('diagnosis.php');
$title = 'Your Vacation Brain Diagnosis';
$topTraits = array_slice($result['traits'] ?? [], 0, 6, true);
$share = sprintf('My Vacation Brain Score is %d — %s. Apparently I need a vacation.', $result['vacation_brain_score'], $result['title']);
$answeredCount = max(0, (int)($result['answered_count'] ?? count($result['answers'] ?? [])));
$questionCount = max($answeredCount, (int)($result['question_count'] ?? $answeredCount));
$diagnosisConfidence = max(1, min(100, (int)($result['diagnosis_confidence'] ?? ($questionCount > 0 ? round(($answeredCount / $questionCount) * 100) : 100))));
$depthLabel = $diagnosisConfidence >= 100 ? 'Full profile depth' : ($diagnosisConfidence >= 75 ? 'High profile depth' : 'Core diagnosis');
$destinationMatches=[];
try{$destinationMatches=(new DestinationRecommendationService(db()))->recommendationsFromTraits($result['traits']??[],3);}catch(Throwable){}
$primaryDestination=$destinationMatches[0]??null;
require __DIR__ . '/partials/header.php';
?>
<section class="result-wrap">
  <div class="shell result-grid">
    <article class="result-card">
      <div class="diagnosis-scoreline"><span>Vacation Brain Score</span><span>•</span><span>Self-Diagnosis <?= (int)$result['diagnosis_score'] ?>/100</span></div>
      <div class="result-score"><?= (int)$result['vacation_brain_score'] ?></div>
      <h1><?= e($result['title']) ?></h1>
      <p class="section-lead" style="margin-bottom:20px"><?= e($result['summary']) ?></p>

      <?php if ($questionCount > DiagnosisService::MIN_ANSWERS): ?>
        <div class="dashboard-card" style="margin:0 0 22px;padding:16px 18px;background:#f7fbfa">
          <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap">
            <div><span class="eyebrow">Diagnosis depth</span><strong style="display:block;margin-top:4px"><?=e($depthLabel)?></strong></div>
            <div style="text-align:right"><strong><?= (int)$answeredCount ?>/<?= (int)$questionCount ?> answers</strong><div class="muted small"><?= (int)$diagnosisConfidence ?>% profile depth</div></div>
          </div>
          <div class="bar" style="margin-top:12px"><span style="width:<?= (int)$diagnosisConfidence ?>%"></span></div>
          <?php if ($answeredCount < $questionCount): ?><p class="microcopy" style="margin:10px 0 0">Your diagnosis is valid now. Answering more questions next time gives Vacation Brain more signal for destination, pacing, food, lodging, and activity recommendations.</p><?php endif; ?>
        </div>
      <?php endif; ?>

      <span class="eyebrow">Vacation prescription</span>
      <ul class="prescription-list">
        <?php foreach ($result['prescription'] as $item): ?><li>✓ <?= e($item) ?></li><?php endforeach; ?>
      </ul>
      <?php if($primaryDestination):?><div class="dashboard-card" style="margin:24px 0;background:#fff8ef"><span class="eyebrow">Image prescription</span><h2 style="margin:6px 0">Apparently you need <?=e($primaryDestination['name'])?>.</h2><p class="muted"><?=e($primaryDestination['short_description']??'Vacation Brain found a destination that fits the answers you just gave.')?></p><?php if(!empty($primaryDestination['_recommendation_reasons'])):?><p class="small"><strong>Because your answers lean toward:</strong> <?=e(implode(' · ',array_slice($primaryDestination['_recommendation_reasons'],0,4)))?></p><?php endif;?><?php if(auth_user_id()):?><a class="button primary" href="<?=e(app_url('vacation-yourself.php?destination_id='.(int)$primaryDestination['id'].'&origin=diagnosis&origin_id='.(int)($result['result_id']??0)))?>">Show me there →</a><?php else:?><a class="button primary" href="<?=e(app_url('signup.php?destination_id='.(int)$primaryDestination['id']))?>">Save my profile & show me there →</a><?php endif;?></div><?php endif;?>
      <div class="share-row">
        <?php if (!auth_user_id()): ?><a class="button secondary" href="<?= e(app_url('signup.php')) ?>">Save My Vacation Brain</a><?php else: ?><a class="button secondary" href="<?= e(app_url('today.php')) ?>">Go to Today</a><?php endif; ?>
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
      <?php if(count($destinationMatches)>1):?><hr style="border:0;border-top:1px solid var(--line);margin:24px 0"><span class="eyebrow">Also suspiciously compatible</span><?php foreach(array_slice($destinationMatches,1) as $match):?><div style="margin-top:12px"><strong><?=e($match['name'])?></strong><div class="muted small"><?=e($match['vibe']??'')?></div></div><?php endforeach;?><?php endif;?>
      <hr style="border:0;border-top:1px solid var(--line);margin:24px 0">
      <p class="muted">This is the beginning of your Vacation Brain profile. Optional diagnosis questions, daily check-ins, and future swipe decks make the profile more confident over time.</p>
    </aside>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
