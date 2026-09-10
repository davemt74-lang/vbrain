<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — AI-Assisted Travel Agent & Off Duty Goods';
$metaDescription = 'Vacation Brain is a fun AI-assisted travel agent for discovering destinations, planning better trips, and shopping travel-inspired Vacation Brain merch.';
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
    'palm-springs-drop-001-hoodie' => ['primary' => '/assets/merch/palm-springs-hoodie-front.webp', 'secondary' => '/assets/merch/palm-springs-hoodie-back.webp'],
    'brighter-day-hoodie' => ['primary' => '/assets/merch/brighter-day-hoodie-front.webp', 'secondary' => '/assets/merch/brighter-day-hoodie-back.webp'],
    'palm-springs-trucker-hat' => ['primary' => '/assets/merch/palm-springs-trucker-hat.webp', 'secondary' => ''],
    'script-vintage-trucker-hat-rust' => ['primary' => '/assets/merch/vintage-trucker-hat-rust.webp', 'secondary' => ''],
    'script-vintage-trucker-hat-navy' => ['primary' => '/assets/merch/vintage-trucker-hat-navy.webp', 'secondary' => ''],
    'palm-springs-embroidered-patch' => ['primary' => '/assets/merch/palm-springs-patch.webp', 'secondary' => ''],
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
            $knownPrimary = trim((string)$fallback['primary']);
            $knownSecondary = trim((string)$fallback['secondary']);
            $dbPrimary = trim((string)($product['image_url'] ?? ''));
            $dbSecondary = trim((string)($product['secondary_image_url'] ?? ''));

            $primary = ($knownPrimary !== '' && local_media_exists($knownPrimary, __DIR__)) ? $knownPrimary : $dbPrimary;
            $secondary = ($knownSecondary !== '' && local_media_exists($knownSecondary, __DIR__)) ? $knownSecondary : $dbSecondary;
            if ($primary !== '' && !local_media_exists($primary, __DIR__)) $primary = '';
            if ($secondary !== '' && !local_media_exists($secondary, __DIR__)) $secondary = '';

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
      <div class="vb-kicker"><span>✦</span>Your AI-assisted travel agent</div>
      <h1>Smarter trips.<br><em>Happier you.</em></h1>
      <p class="vb-hero-lead">Tell Vacation Brain what sounds good, what sounds terrible, how much energy you have, and what kind of trip you actually want. We turn that into destination ideas, research, itineraries, and useful next steps.</p>
      <div class="vb-hero-actions">
        <a class="button vb-primary" href="<?=e(app_url('agent.php'))?>">Start planning →</a>
        <a class="button vb-ghost" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain diagnosis</a>
      </div>
      <div class="vb-hero-proof" aria-label="Vacation Brain travel planning features">
        <div><strong>01</strong><span>Mood-aware trip matching</span></div>
        <div><strong>02</strong><span>Destination research</span></div>
        <div><strong>03</strong><span>Personalized itineraries</span></div>
      </div>
    </div>

    <div class="vb-agent-stage" aria-label="Vacation Brain AI travel agent preview">
      <div class="vb-sun" aria-hidden="true"></div>
      <div class="vb-palm vb-palm-one" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></div>
      <div class="vb-palm vb-palm-two" aria-hidden="true"><i></i><i></i><i></i><b></b></div>
      <div class="vb-phone">
        <div class="vb-phone-top"><span>9:41</span><b>Vacation Brain</b><span>•••</span></div>
        <div class="vb-phone-copy"><small>Good morning.</small><h2>Where's your mind today?</h2></div>
        <div class="vb-agent-prompt">Warm water, great food, no alarms, and somewhere I have never been.</div>
        <div class="vb-mood-row"><span>☀ Beach</span><span>⌁ Relax</span><span>✦ Food</span></div>
        <div class="vb-ai-card">
          <div class="vb-ai-card-head"><span>AI trip suggestion</span><strong>92% match</strong></div>
          <div class="vb-destination-art vb-destination-coral"><span>CURACAO</span><i></i><b></b></div>
          <h3>Curaçao · 6 nights</h3>
          <p>Colorful, warm, easy to explore, excellent food, and plenty of room to do absolutely nothing.</p>
          <div class="vb-ai-tags"><span>Warm</span><span>Food</span><span>Low stress</span></div>
        </div>
        <div class="vb-phone-nav"><span>⌂<small>Home</small></span><span>⌕<small>Explore</small></span><span>▣<small>Trips</small></span><span>☺<small>Profile</small></span></div>
      </div>
      <div class="vb-float-card vb-float-left"><small>Brain status</small><strong>Needs somewhere warm.</strong><span>Diagnosis: obvious.</span></div>
      <div class="vb-float-card vb-float-right"><small>Next idea</small><strong>Tokyo after dark</strong><span>Food + neon + wandering</span></div>
    </div>
  </div>
</section>

<section class="vb-capabilities" aria-label="Vacation Brain capabilities">
  <div class="shell vb-capability-grid">
    <a href="<?=e(app_url('agent.php'))?>"><span class="vb-cap-icon">✦</span><div><strong>AI Trip Planning</strong><small>Talk it out instead of filling out forms.</small></div><b>→</b></a>
    <a href="<?=e(app_url('destinations.php'))?>"><span class="vb-cap-icon">⌖</span><div><strong>Destination Ideas</strong><small>Research places, lodging, weather and things to do.</small></div><b>→</b></a>
    <a href="<?=e(app_url('vacation-yourself.php'))?>"><span class="vb-cap-icon">◎</span><div><strong>Vacation Yourself</strong><small>Visualize the trip before you book it.</small></div><b>→</b></a>
    <a href="<?=e(app_url('shop.php'))?>"><span class="vb-cap-icon">▣</span><div><strong>Vacation Brain Merch</strong><small>Wear the part of you that already left town.</small></div><b>→</b></a>
  </div>
</section>

<?php if ($merchProducts): ?>
<section class="vb-shop" id="shop">
  <div class="shell">
    <div class="vb-section-head">
      <div><span class="vb-eyebrow">Travel more · wear the mindset</span><h2>Vacation Brain Merch</h2></div>
      <div class="vb-section-side"><p>Vintage-inspired travel goods built around the places, moods and bad decisions Vacation Brain recommends.</p><a href="<?=e(app_url('shop.php'))?>">View all products →</a></div>
    </div>

    <div class="vb-product-grid">
      <?php foreach ($merchProducts as $product): ?>
        <?php
          $primary = (string)($product['display_primary'] ?? '');
          $secondary = (string)($product['display_secondary'] ?? '');
          $productUrl = app_url('shop-product.php?slug='.urlencode((string)$product['slug']));
        ?>
        <article class="vb-product <?=$secondary !== '' ? 'vb-product--two-view' : ''?>">
          <a class="vb-product-media" href="<?=e($productUrl)?>" aria-label="View <?=e((string)$product['name'])?>">
            <?php if ($primary !== ''): ?>
              <?php if ($secondary !== ''): ?>
                <div class="vb-product-view"><span>Front</span><img src="<?=e($primary)?>" alt="<?=e((string)$product['name'])?> front view" loading="lazy"></div>
                <div class="vb-product-view"><span>Back</span><img src="<?=e($secondary)?>" alt="<?=e((string)$product['name'])?> back view" loading="lazy"></div>
              <?php else: ?>
                <div class="vb-product-view vb-product-view--single"><img src="<?=e($primary)?>" alt="<?=e((string)$product['name'])?>" loading="lazy"></div>
              <?php endif; ?>
            <?php else: ?>
              <div class="vb-product-placeholder"><span>VACATION<br>BRAIN</span></div>
            <?php endif; ?>
          </a>
          <div class="vb-product-info">
            <div class="vb-product-type"><?=e(strtoupper((string)$product['product_type']))?></div>
            <h3><?=e((string)$product['name'])?></h3>
            <p><?=e((string)$product['short_description'])?></p>
            <div class="vb-product-bottom"><strong>$<?=number_format((float)$product['price'], 2)?></strong><a href="<?=e($productUrl)?>">View product →</a></div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="vb-agent-section">
  <div class="shell vb-agent-grid">
    <div class="vb-agent-copy">
      <span class="vb-eyebrow">Your trip, without the spreadsheet</span>
      <h2>Ask a travel agent that already knows your Vacation Brain.</h2>
      <p>The same agent can help you discover somewhere new, compare ideas, research a destination, remember what you liked, and turn the winning idea into a trip plan.</p>
      <a class="button vb-primary" href="<?=e(app_url('agent.php'))?>">Talk to Vacation Brain →</a>
    </div>
    <div class="vb-conversation">
      <div class="vb-chat-row vb-chat-user"><span>You</span><p>I want a four-day trip in October. Good food, walkable, not too touristy.</p></div>
      <div class="vb-chat-row vb-chat-agent"><span>VB</span><div><p>Three places fit that version of you. I'd start with Montréal.</p><div class="vb-chat-result"><strong>Montréal</strong><small>Food 96 · Walkability 94 · October vibe 91</small></div></div></div>
      <div class="vb-chat-composer"><span>Ask Vacation Brain anything…</span><b>↑</b></div>
    </div>
  </div>
</section>

<section class="vb-closing">
  <div class="vb-illustrated-landscape" aria-hidden="true">
    <div class="vb-illustrated-sun"></div>
    <div class="vb-mountain vb-mountain-one"></div>
    <div class="vb-mountain vb-mountain-two"></div>
    <div class="vb-water"></div>
    <div class="vb-closing-palm vb-closing-palm-left"><i></i><b></b></div>
    <div class="vb-closing-palm vb-closing-palm-right"><i></i><b></b></div>
  </div>
  <div class="shell vb-closing-content">
    <div><span class="vb-eyebrow">More than a trip</span><h2>A brighter you awaits.</h2></div>
    <div><p>Better destinations. Better perspective. A little less time staring at seventeen open travel tabs.</p><a class="button" href="<?=e(app_url('agent.php'))?>">Start planning →</a></div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
