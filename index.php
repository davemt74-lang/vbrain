<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — Diagnose Your Need to Get Away';
$metaDescription = 'Take the Vacation Brain self-diagnosis, find out how mentally checked out you are, and shop Vacation Brain Off Duty Goods.';
$pageStyles = ['assets/landing.css'];

$merchProducts = [];
if (db_table_exists('merch_catalog_products')) {
    try {
        $wantedSlugs = [
            'palm-springs-drop-001-hoodie',
            'brighter-day-hoodie',
            'palm-springs-trucker-hat',
            'script-vintage-trucker-hat-rust',
            'script-vintage-trucker-hat-navy',
            'palm-springs-embroidered-patch',
        ];
        $placeholders = implode(',', array_fill(0, count($wantedSlugs), '?'));
        $sampleClause = (db_column_exists('merch_catalog_products', 'is_sample') && !sample_data_enabled()) ? ' AND is_sample=0' : '';
        $stmt = db()->prepare("SELECT id,slug,name,short_description,product_type,price,image_url,secondary_image_url FROM merch_catalog_products WHERE status='active' AND slug IN ($placeholders)".$sampleClause);
        $stmt->execute($wantedSlugs);
        $merchProducts = $stmt->fetchAll();
        $order = array_flip($wantedSlugs);
        usort($merchProducts, static fn(array $a, array $b): int => ($order[$a['slug']] ?? 999) <=> ($order[$b['slug']] ?? 999));
    } catch (Throwable) {
        $merchProducts = [];
    }
}

require __DIR__ . '/partials/header.php';
?>
<section class="landing-hero">
  <div class="shell landing-hero-grid">
    <div>
      <span class="landing-kicker">A completely unnecessary travel diagnosis</span>
      <h1>Your brain has already <span>left town.</span></h1>
      <p class="landing-hero-copy">Vacation Brain turns your suspicious amount of travel daydreaming into a score, a personality profile, and an entirely non-medical prescription for getting out of here.</p>
      <div class="landing-hero-actions">
        <a class="button primary landing-primary" href="<?=e(app_url('diagnosis.php'))?>">Diagnose my Vacation Brain →</a>
        <a class="button secondary landing-secondary" href="<?=e(app_url('professional-assessment.php'))?>">Get a professional opinion</a>
      </div>
      <ul class="landing-proof" aria-label="Diagnosis details">
        <li>10 ridiculous swipes</li>
        <li>About two minutes</li>
        <li>Medically useless</li>
      </ul>
      <p class="microcopy"><?=e(diagnosis_disclaimer())?></p>
    </div>

    <div class="landing-diagnosis-stage" aria-label="Sample Vacation Brain diagnosis">
      <div class="landing-diagnosis-card">
        <div class="landing-card-head">
          <div>
            <div class="landing-card-label">Vacation Brain Score</div>
            <div class="landing-score">742</div>
          </div>
          <span class="landing-severity">Severe</span>
        </div>
        <div class="landing-meter"><span></span></div>
        <p class="landing-card-quote">“At this point your employer should probably be concerned.”</p>
        <div class="landing-prescription"><strong>Vacation prescription</strong>5–7 nights somewhere warm. Direct flight preferred. No scheduled activities before 10 AM.</div>
      </div>
      <div class="landing-floating-chip one">Day 38 of thinking about vacation.</div>
      <div class="landing-floating-chip two">Symptoms worsening near airport ads.</div>
    </div>
  </div>
</section>

<?php if ($merchProducts): ?>
<section class="landing-merch" id="shop">
  <div class="shell">
    <div class="landing-section-head">
      <div>
        <span class="eyebrow">Vacation Brain · Off Duty Goods</span>
        <h2>Wear the diagnosis.</h2>
      </div>
      <p>The first Vacation Brain drop: Palm Springs graphics, vintage trucker hats, embroidered-style patches, and hoodies with proper front and back artwork.</p>
    </div>

    <div class="landing-merch-grid">
      <?php foreach ($merchProducts as $product): ?>
        <article class="landing-product">
          <a class="landing-product-media" href="<?=e(app_url('shop-product.php?slug='.urlencode((string)$product['slug'])))?>">
            <?php if (!empty($product['image_url'])): ?>
              <img src="<?=e(media_url((string)$product['image_url']))?>" alt="<?=e((string)$product['name'])?>">
            <?php endif; ?>
            <?php if (!empty($product['secondary_image_url'])): ?><span class="landing-product-badge">Front + Back</span><?php endif; ?>
          </a>
          <div class="landing-product-copy">
            <div class="landing-product-type"><?=e((string)$product['product_type'])?></div>
            <h3><?=e((string)$product['name'])?></h3>
            <p><?=e((string)$product['short_description'])?></p>
            <div class="landing-product-foot">
              <span class="landing-product-price">$<?=number_format((float)$product['price'], 2)?></span>
              <a class="landing-product-link" href="<?=e(app_url('shop-product.php?slug='.urlencode((string)$product['slug'])))?>">View product →</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="landing-merch-cta"><a class="button secondary" href="<?=e(app_url('shop.php'))?>">Shop all Off Duty Goods</a></div>
  </div>
</section>
<?php endif; ?>

<section class="landing-how">
  <div class="shell landing-how-grid">
    <div>
      <span class="eyebrow">How this gets worse</span>
      <h2>Ten swipes. One diagnosis. Zero medical value.</h2>
      <p class="landing-how-copy">The jokes are the front door. Underneath them, Vacation Brain starts learning the kind of escape you actually want so it can make better destination, dream-trip, and Vacation Yourself suggestions later.</p>
    </div>
    <div class="landing-steps">
      <article class="landing-step"><span class="landing-step-num">1</span><div><h3>Answer suspiciously revealing questions</h3><p>Pick between vacation scenarios that are dumb enough to be fun and specific enough to say something useful.</p></div></article>
      <article class="landing-step"><span class="landing-step-num">2</span><div><h3>Receive your Vacation Brain diagnosis</h3><p>Get a score, profile signals, and a vacation prescription that should absolutely not be submitted to your insurance company.</p></div></article>
      <article class="landing-step"><span class="landing-step-num">3</span><div><h3>Let the brain keep learning</h3><p>Swipes, dream trips, destinations, and fake vacations become part of a more useful personal travel profile over time.</p></div></article>
    </div>
  </div>
</section>

<section class="landing-bottom-callout">
  <div class="shell">
    <div class="landing-callout">
      <div><h2>There is only one responsible next step.</h2><p>Take the diagnosis before your Vacation Brain starts booking imaginary hotels without you.</p></div>
      <a class="button" href="<?=e(app_url('diagnosis.php'))?>">Take the diagnosis →</a>
    </div>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>
