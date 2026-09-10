<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — AI-Assisted Travel Agent';
$metaDescription = 'Vacation Brain is your AI-assisted travel agent for discovering destinations, matching trips to your mood, planning better vacations, and finding travel-inspired merch.';
$pageStyles = ['assets/landing.css', 'assets/landing-merch-fix.css'];

$heroAsset = '/assets/landing/hero-resort.webp';
$phoneAsset = '/assets/landing/phone-ai-agent.webp';
$heroExists = local_media_exists($heroAsset, __DIR__);
$phoneExists = local_media_exists($phoneAsset, __DIR__);

$wantedSlugs = [
    'palm-springs-trucker-hat',
    'script-vintage-trucker-hat-navy',
    'palm-springs-drop-001-hoodie',
    'brighter-day-hoodie',
];

$knownMerchMedia = [
    'palm-springs-trucker-hat' => [
        'primary' => '/assets/landing/palm-springs-trucker-hat.webp',
        'fallback' => '/assets/merch/palm-springs-trucker-hat.webp',
        'secondary' => '',
    ],
    'script-vintage-trucker-hat-navy' => [
        'primary' => '/assets/landing/vintage-script-trucker-hat.webp',
        'fallback' => '/assets/merch/vintage-trucker-hat-navy.webp',
        'secondary' => '',
    ],
    'palm-springs-drop-001-hoodie' => [
        'primary' => '/assets/merch/palm-springs-hoodie-front.webp',
        'fallback' => '',
        'secondary' => '/assets/merch/palm-springs-hoodie-back.webp',
    ],
    'brighter-day-hoodie' => [
        'primary' => '/assets/merch/brighter-day-hoodie-front.webp',
        'fallback' => '',
        'secondary' => '/assets/merch/brighter-day-hoodie-back.webp',
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
            $known = $knownMerchMedia[$slug] ?? ['primary' => '', 'fallback' => '', 'secondary' => ''];
            $preferredPrimary = trim((string)($known['primary'] ?? ''));
            $fallbackPrimary = trim((string)($known['fallback'] ?? ''));
            $knownSecondary = trim((string)($known['secondary'] ?? ''));
            $dbPrimary = trim((string)($product['image_url'] ?? ''));
            $dbSecondary = trim((string)($product['secondary_image_url'] ?? ''));

            // The catalog is authoritative. Bundled media is only a fallback for products
            // that have not yet been given catalog images.
            $primary = '';
            foreach ([$dbPrimary, $preferredPrimary, $fallbackPrimary] as $candidate) {
                if ($candidate !== '' && local_media_exists($candidate, __DIR__)) {
                    $primary = $candidate;
                    break;
                }
            }

            $secondary = '';
            foreach ([$dbSecondary, $knownSecondary] as $candidate) {
                if ($candidate !== '' && local_media_exists($candidate, __DIR__)) {
                    $secondary = $candidate;
                    break;
                }
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

<section class="vb-hero <?=$heroExists ? 'vb-hero--has-art' : ''?>"<?php if ($heroExists): ?> style="--vb-hero-image:url('<?=e(media_url($heroAsset))?>')"<?php endif; ?>>
  <div class="vb-hero-shade" aria-hidden="true"></div>
  <div class="shell vb-hero-layout">
    <div class="vb-hero-copy">
      <div class="vb-eyebrow vb-hero-eyebrow">YOUR AI TRAVEL AGENT</div>
      <h1>Smarter Trips<br><em>Happier You</em></h1>
      <p>Vacation Brain is your AI-assisted travel agent, helping you discover amazing destinations, match trips to your mood, and plan better vacations — with less stress and more magic.</p>
      <div class="vb-hero-actions">
        <a class="button vb-primary" href="<?=e(app_url('diagnosis.php'))?>">Start Planning <span>→</span></a>
        <a class="button vb-outline" href="#shop"><span class="vb-bag">▢</span> Explore Merch</a>
      </div>
      <div class="vb-hero-stats" aria-label="Vacation Brain benefits">
        <div><strong>AI</strong><small>Assisted planning</small></div>
        <div><strong>24/7</strong><small>Trip inspiration</small></div>
        <div><strong>YOU</strong><small>At the center</small></div>
      </div>
    </div>

    <div class="vb-hero-device" aria-label="Vacation Brain mobile travel agent preview">
      <?php if ($phoneExists): ?>
        <img src="<?=e(media_url($phoneAsset))?>" alt="Vacation Brain AI travel agent app showing an Amalfi Coast trip suggestion">
      <?php else: ?>
        <div class="vb-phone-fallback">
          <span>Vacation Brain</span><strong>Where's your<br>mind today?</strong><small>AI Trip Suggestions</small><b>Amalfi Coast, Italy</b>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="vb-capabilities" aria-label="Vacation Brain travel tools">
  <div class="shell vb-capability-grid">
    <a href="<?=e(app_url('diagnosis.php'))?>"><span class="vb-cap-icon">◈</span><div><strong>Trip Matching</strong><small>Tell us your mood, and our AI finds destinations for you.</small></div></a>
    <a href="<?=e(app_url('destinations.php'))?>"><span class="vb-cap-icon">●</span><div><strong>Destination Ideas</strong><small>Curated places, hidden gems, and personalized suggestions.</small></div></a>
    <a href="#shop"><span class="vb-cap-icon vb-cap-icon--warm">▢</span><div><strong>Vacation Brain Merch</strong><small>Take the journey with premium gear for dreamers and doers.</small></div></a>
    <a href="<?=e(app_url('agent.php'))?>"><span class="vb-cap-icon">▣</span><div><strong>Personalized Itineraries</strong><small>Custom day-by-day plans based on your interests and travel style.</small></div></a>
  </div>
</section>

<section class="vb-shop" id="shop">
  <div class="shell">
    <div class="vb-shop-head">
      <div><span class="vb-eyebrow">TRAVEL MORE. WEAR THE MINDSET.</span><h2>Vacation Brain Merch</h2></div>
      <a class="vb-view-all" href="<?=e(app_url('shop.php'))?>">View All Products <span>→</span></a>
    </div>

    <?php if ($merchProducts): ?>
      <div class="vb-product-grid">
        <?php foreach ($merchProducts as $product): ?>
          <?php
            $primary = (string)($product['display_primary'] ?? '');
            $secondary = (string)($product['display_secondary'] ?? '');
            $productUrl = app_url('shop-product.php?slug='.urlencode((string)$product['slug']));
            $productType = strtolower(trim((string)($product['product_type'] ?? '')));
            $isHoodie = str_contains($productType, 'hoodie') || $secondary !== '';
          ?>
          <article class="vb-product <?=$isHoodie ? 'vb-product--hoodie' : 'vb-product--hat'?>">
            <a class="vb-product-media" href="<?=e($productUrl)?>" aria-label="View <?=e((string)$product['name'])?>">
              <?php if ($primary !== ''): ?>
                <?php if ($isHoodie): ?>
                  <div class="vb-product-view vb-product-view--swap <?=$secondary !== '' ? 'has-secondary' : ''?>">
                    <img class="vb-product-image vb-product-image--front" src="<?=e($primary)?>" alt="<?=e((string)$product['name'])?> front" loading="lazy">
                    <?php if ($secondary !== ''): ?>
                      <img class="vb-product-image vb-product-image--back" src="<?=e($secondary)?>" alt="<?=e((string)$product['name'])?> back" loading="lazy">
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="vb-product-view vb-product-view--single"><img src="<?=e($primary)?>" alt="<?=e((string)$product['name'])?>" loading="lazy"></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="vb-product-placeholder">VACATION<br>BRAIN</div>
              <?php endif; ?>
            </a>
            <div class="vb-product-info">
              <h3><?=e((string)$product['name'])?></h3>
              <p><?=e((string)$product['short_description'])?></p>
              <div class="vb-product-buy">
                <strong>$<?=number_format((float)$product['price'], 2)?></strong>
                <a href="<?=e($productUrl)?>"><?=$isHoodie ? 'View Details' : 'Add to Cart'?> <span>▢</span></a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="vb-shop-empty"><strong>Vacation Brain merch is landing soon.</strong><a href="<?=e(app_url('shop.php'))?>">Visit the shop →</a></div>
    <?php endif; ?>
  </div>
</section>

<section class="vb-closing" id="about">
  <div class="vb-closing-art" aria-hidden="true">
    <span class="vb-sun"></span><span class="vb-mountain vb-mountain-a"></span><span class="vb-mountain vb-mountain-b"></span><span class="vb-water"></span>
    <span class="vb-palm vb-palm-a"></span><span class="vb-palm vb-palm-b"></span>
  </div>
  <div class="shell vb-closing-content">
    <div class="vb-closing-title"><span class="vb-eyebrow">MORE THAN A TRIP</span><h2>A Brighter You<br>Awaits</h2></div>
    <div class="vb-closing-copy">
      <p>Better destinations.<br>Brighter perspectives.<br>A more you.</p>
      <a class="button vb-primary" href="<?=e(app_url('diagnosis.php'))?>">Start Planning <span>→</span></a>
    </div>
    <div class="vb-signpost" aria-hidden="true"><span>EXPLORE</span><span>WANDER</span><span>BELONG</span><span>BE BRIGHTER</span></div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
