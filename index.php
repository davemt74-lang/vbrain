<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — Your Brain Already Left Town';
$metaDescription = 'Take the Vacation Brain self-diagnosis, discover how mentally checked out you are, explore your escape profile, and shop Off Duty Goods.';
$pageStyles = ['assets/landing.css'];

$wantedSlugs = [
    'palm-springs-drop-001-hoodie',
    'brighter-day-hoodie',
    'palm-springs-trucker-hat',
    'script-vintage-trucker-hat-rust',
    'script-vintage-trucker-hat-navy',
    'palm-springs-embroidered-patch',
];

$knownMerchMedia = [
    'palm-springs-drop-001-hoodie' => [
        'primary' => '/assets/merch/palm-springs-hoodie-front.webp',
        'secondary' => '/assets/merch/palm-springs-hoodie-back.webp',
    ],
    'brighter-day-hoodie' => [
        'primary' => '/assets/merch/brighter-day-hoodie-front.webp',
        'secondary' => '/assets/merch/brighter-day-hoodie-back.webp',
    ],
    'palm-springs-trucker-hat' => [
        'primary' => '/assets/merch/palm-springs-trucker-hat.webp',
        'secondary' => '',
    ],
    'script-vintage-trucker-hat-rust' => [
        'primary' => '/assets/merch/vintage-trucker-hat-rust.webp',
        'secondary' => '',
    ],
    'script-vintage-trucker-hat-navy' => [
        'primary' => '/assets/merch/vintage-trucker-hat-navy.webp',
        'secondary' => '',
    ],
    'palm-springs-embroidered-patch' => [
        'primary' => '/assets/merch/palm-springs-patch.webp',
        'secondary' => '',
    ],
];

$merchProducts = [];
if (db_table_exists('merch_catalog_products')) {
    try {
        $placeholders = implode(',', array_fill(0, count($wantedSlugs), '?'));
        $sampleClause = (db_column_exists('merch_catalog_products', 'is_sample') && !sample_data_enabled()) ? ' AND is_sample=0' : '';
        $stmt = db()->prepare("SELECT id,slug,name,short_description,product_type,price,image_url,secondary_image_url FROM merch_catalog_products WHERE status='active' AND slug IN ($placeholders)".$sampleClause);
        $stmt->execute($wantedSlugs);
        $merchProducts = $stmt->fetchAll() ?: [];
        $order = array_flip($wantedSlugs);
        usort($merchProducts, static fn(array $a, array $b): int => ($order[$a['slug']] ?? 999) <=> ($order[$b['slug']] ?? 999));

        foreach ($merchProducts as &$product) {
            $slug = (string)$product['slug'];
            $fallback = $knownMerchMedia[$slug] ?? ['primary' => '', 'secondary' => ''];
            $primary = trim((string)($product['image_url'] ?? ''));
            $secondary = trim((string)($product['secondary_image_url'] ?? ''));

            if ($primary === '' || !local_media_exists($primary, __DIR__)) {
                $primary = (string)$fallback['primary'];
            }
            if ($secondary === '' || !local_media_exists($secondary, __DIR__)) {
                $secondary = (string)$fallback['secondary'];
            }
            if ($primary !== '' && !local_media_exists($primary, __DIR__)) {
                $primary = '';
            }
            if ($secondary !== '' && !local_media_exists($secondary, __DIR__)) {
                $secondary = '';
            }

            $product['display_primary'] = $primary !== '' ? media_url($primary) : '';
            $product['display_secondary'] = $secondary !== '' ? media_url($secondary) : '';
        }
        unset($product);
    } catch (Throwable $e) {
        error_log('Vacation Brain landing merch: '.$e->getMessage());
        $merchProducts = [];
    }
}

require __DIR__ . '/partials/header.php';
?>
<section class="vb-hero">
  <div class="shell vb-hero-grid">
    <div class="vb-hero-copy">
      <div class="vb-kicker"><span></span>Vacation Brain diagnosis lab</div>
      <h1>You don't need a vacation.<em>Your brain already took one.</em></h1>
      <p>We turn your suspicious amount of travel daydreaming into a score, a personality profile, and a completely non-medical prescription for getting out of here.</p>
      <div class="vb-hero-actions">
        <a class="button vb-primary" href="<?=e(app_url('diagnosis.php'))?>">Diagnose my Vacation Brain →</a>
        <a class="button vb-ghost" href="#shop">Shop the first drop</a>
      </div>
      <div class="vb-hero-meta">
        <span><strong>10</strong> ridiculous swipes</span>
        <span><strong>2 min</strong> average diagnosis</span>
        <span><strong>0%</strong> medical value</span>
      </div>
      <p class="microcopy vb-disclaimer"><?=e(diagnosis_disclaimer())?></p>
    </div>

    <div class="vb-console" aria-label="Sample Vacation Brain diagnosis">
      <div class="vb-console-top">
        <div><span class="vb-console-label">Live brain status</span><strong>Vacation detected</strong></div>
        <span class="vb-status-dot">Severe</span>
      </div>
      <div class="vb-score-row">
        <div class="vb-score">742</div>
        <div class="vb-score-copy"><span>Vacation Brain Score</span><strong>Mentally 2,143 miles away</strong></div>
      </div>
      <div class="vb-meter"><span></span></div>
      <div class="vb-symptoms">
        <div><span>01</span><p>Opening hotel tabs during work hours.</p></div>
        <div><span>02</span><p>Strong emotional response to airport ads.</p></div>
        <div><span>03</span><p>Already owns vacation clothes for a trip not booked.</p></div>
      </div>
      <div class="vb-prescription"><span>Suggested treatment</span><strong>5–7 nights somewhere warm.</strong><small>No scheduled activities before 10 AM.</small></div>
    </div>
  </div>
  <div class="shell vb-marquee" aria-label="Vacation Brain process">
    <span>DIAGNOSE</span><i>→</i><span>DAYDREAM</span><i>→</i><span>ESCAPE</span><i>→</i><span>FAKE VACATION</span><i>→</i><span>REPEAT AS NEEDED</span>
  </div>
</section>

<?php if ($merchProducts): ?>
<section class="vb-shop" id="shop">
  <div class="shell">
    <div class="vb-section-head">
      <div><span class="vb-eyebrow">Vacation Brain · Off Duty Goods</span><h2>Wear the diagnosis.</h2></div>
      <div class="vb-section-side"><p>Palm Springs graphics, vintage trucker hats and the first Vacation Brain hoodies. Built like actual products, not placeholder merch.</p><a href="<?=e(app_url('shop.php'))?>">Shop everything →</a></div>
    </div>

    <div class="vb-product-grid">
      <?php foreach ($merchProducts as $index => $product): ?>
        <?php $featured = $index < 2; $primary = (string)($product['display_primary'] ?? ''); $secondary = (string)($product['display_secondary'] ?? ''); ?>
        <article class="vb-product <?=$featured ? 'vb-product--featured' : ''?>">
          <a class="vb-product-media" href="<?=e(app_url('shop-product.php?slug='.urlencode((string)$product['slug'])))?>" aria-label="View <?=e((string)$product['name'])?>">
            <?php if ($primary !== ''): ?>
              <img class="vb-product-front" src="<?=e($primary)?>" alt="<?=e((string)$product['name'])?>" loading="lazy">
              <?php if ($secondary !== ''): ?><img class="vb-product-back" src="<?=e($secondary)?>" alt="<?=e((string)$product['name'])?> back view" loading="lazy"><?php endif; ?>
            <?php else: ?>
              <div class="vb-product-placeholder"><span>VACATION<br>BRAIN</span></div>
            <?php endif; ?>
            <div class="vb-product-tags">
              <span><?=e(strtoupper((string)$product['product_type']))?></span>
              <?php if ($secondary !== ''): ?><span>FRONT + BACK</span><?php endif; ?>
            </div>
          </a>
          <div class="vb-product-info">
            <div><h3><?=e((string)$product['name'])?></h3><p><?=e((string)$product['short_description'])?></p></div>
            <div class="vb-product-bottom"><strong>$<?=number_format((float)$product['price'], 2)?></strong><a href="<?=e(app_url('shop-product.php?slug='.urlencode((string)$product['slug'])))?>">View →</a></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="vb-after">
  <div class="shell">
    <div class="vb-section-head vb-section-head--light">
      <div><span class="vb-eyebrow">After the diagnosis</span><h2>Then it starts getting useful.</h2></div>
      <div class="vb-section-side"><p>The jokes are the front door. Every swipe, destination and fake vacation helps Vacation Brain understand what kind of escape you actually want.</p></div>
    </div>
    <div class="vb-feature-grid">
      <a class="vb-feature" href="<?=e(app_url('destinations.php'))?>"><span>01</span><div><small>Explore</small><h3>Find somewhere better</h3><p>Browse destination ideas, research places, weather, lodging and things worth leaving the hotel for.</p></div><b>↗</b></a>
      <a class="vb-feature" href="<?=e(app_url('vacation-yourself.php'))?>"><span>02</span><div><small>Vacation Yourself</small><h3>See the trip before you take it</h3><p>Use your own photos to generate fake vacation moments matched to your destination and travel profile.</p></div><b>↗</b></a>
      <a class="vb-feature" href="<?=e(app_url('dream.php'))?>"><span>03</span><div><small>Dream Trips</small><h3>Save the escape plan</h3><p>Keep the places, ideas and visual daydreams your future self is apparently responsible for booking.</p></div><b>↗</b></a>
    </div>
  </div>
</section>

<section class="vb-method">
  <div class="shell vb-method-grid">
    <div class="vb-method-title"><span class="vb-eyebrow">The science-ish method</span><h2>A surprisingly useful profile disguised as nonsense.</h2></div>
    <div class="vb-method-steps">
      <article><strong>1</strong><div><h3>Swipe honestly</h3><p>Choose between travel scenarios specific enough to reveal what you actually want.</p></div></article>
      <article><strong>2</strong><div><h3>Get diagnosed</h3><p>Receive a score, archetype and vacation prescription that should not be submitted to insurance.</p></div></article>
      <article><strong>3</strong><div><h3>Keep daydreaming</h3><p>Your interactions become a better personal travel profile instead of disappearing after one quiz.</p></div></article>
    </div>
  </div>
</section>

<section class="vb-final">
  <div class="shell">
    <div class="vb-final-card">
      <div><span>VACATION BRAIN</span><h2>There is only one responsible next step.</h2><p>Find out how far gone you are before your brain starts booking imaginary hotels without you.</p></div>
      <div class="vb-final-actions"><a class="button" href="<?=e(app_url('diagnosis.php'))?>">Take the diagnosis →</a><a href="<?=e(app_url('professional-assessment.php'))?>">Or get a professional opinion</a></div>
    </div>
  </div>
</section>
<?php require __DIR__ . '/partials/footer.php'; ?>