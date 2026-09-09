<?php
require __DIR__ . '/app/bootstrap.php';

$title = 'Vacation Brain — Diagnose It. Wear It. Go Somewhere Better.';
$metaDescription = 'Take the Vacation Brain self-diagnosis, get your completely non-medical vacation prescription, and shop Vacation Brain Off Duty Goods.';
$extraStylesheets = ['assets/landing.css'];

$merch = [];
try {
    if (db_table_exists('merch_catalog_products')) {
        $sampleClause = db_column_exists('merch_catalog_products', 'is_sample') ? ' AND is_sample=0' : '';
        $stmt = db()->query("SELECT slug,name,short_description,product_type,sku,price,compare_at_price,image_url,secondary_image_url,featured,sort_order FROM merch_catalog_products WHERE status='active'".$sampleClause." AND sku IN ('VB-PS-HOOD-001','VB-BD-HOOD-001','VB-PS-HAT-001','VB-SV-HAT-RUST','VB-SV-HAT-NAVY','VB-PS-PATCH-001') ORDER BY featured DESC,sort_order ASC,name ASC");
        $merch = $stmt->fetchAll() ?: [];
    }
} catch (Throwable $e) {
    $merch = [];
}

if (!$merch) {
    $merch = [
        ['slug'=>'palm-springs-drop-001-hoodie','name'=>'Palm Springs Drop 001 Hoodie','short_description'=>'Vintage cream. Pool days. Desert nights.','product_type'=>'hoodie','sku'=>'VB-PS-HOOD-001','price'=>64.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/palm-springs-hoodie-front.webp','secondary_image_url'=>'/assets/merch/palm-springs-hoodie-back.webp'],
        ['slug'=>'brighter-day-hoodie','name'=>'A Brighter Day Hoodie','short_description'=>'Minimal front. Oversized twin-palm back graphic.','product_type'=>'hoodie','sku'=>'VB-BD-HOOD-001','price'=>64.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/brighter-day-hoodie-front.webp','secondary_image_url'=>'/assets/merch/brighter-day-hoodie-back.webp'],
        ['slug'=>'palm-springs-trucker-hat','name'=>'Palm Springs Trucker Hat','short_description'=>'Cream, navy mesh, weathered rust brim.','product_type'=>'hat','sku'=>'VB-PS-HAT-001','price'=>29.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/palm-springs-trucker-hat.webp','secondary_image_url'=>null],
        ['slug'=>'script-vintage-trucker-hat-rust','name'=>'Script Vintage Trucker Hat — Rust','short_description'=>'Distressed Vacation Brain script with a rust brim.','product_type'=>'hat','sku'=>'VB-SV-HAT-RUST','price'=>29.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/vintage-trucker-hat-rust.webp','secondary_image_url'=>null],
        ['slug'=>'script-vintage-trucker-hat-navy','name'=>'Script Vintage Trucker Hat — Navy','short_description'=>'The same questionable priorities, now in navy.','product_type'=>'hat','sku'=>'VB-SV-HAT-NAVY','price'=>29.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/vintage-trucker-hat-navy.webp','secondary_image_url'=>null],
        ['slug'=>'palm-springs-embroidered-patch','name'=>'Palm Springs Embroidered Patch','short_description'=>'Pool, palms, mountains, sunset. Evidence of intent.','product_type'=>'patch','sku'=>'VB-PS-PATCH-001','price'=>9.99,'compare_at_price'=>null,'image_url'=>'/assets/merch/palm-springs-patch.webp','secondary_image_url'=>null],
    ];
}

require __DIR__ . '/partials/header.php';
?>
<div class="vb-landing">
  <section class="vb-hero">
    <div class="shell vb-hero-grid">
      <div class="vb-hero-copy">
        <span class="vb-kicker">Vacation Brain · Completely Non-Medical Travel Consultation</span>
        <h1>Your brain is already <span>on vacation.</span></h1>
        <p>We just diagnose how far gone it is. Take a quick Vacation Brain assessment, get a score and an absurdly specific travel prescription, then turn the whole condition into trips, fake vacations and questionable merch decisions.</p>
        <div class="vb-hero-actions">
          <a class="vb-button vb-button-primary" href="<?=e(app_url('diagnosis.php'))?>">Take the Vacation Brain Diagnosis</a>
          <a class="vb-button vb-button-ghost" href="#off-duty-goods">Shop Off Duty Goods ↓</a>
        </div>
        <div class="vb-proof-row" aria-label="Vacation Brain features">
          <span><b>10</b> quick swipes</span>
          <span><b>1</b> unnecessary diagnosis</span>
          <span><b>∞</b> reasons to leave town</span>
        </div>
        <p class="vb-disclaimer"><?=e(diagnosis_disclaimer())?></p>
      </div>

      <div class="vb-hero-stage" aria-label="Vacation Brain diagnosis preview">
        <div class="vb-sun" aria-hidden="true"></div>
        <article class="vb-diagnosis-card">
          <div class="vb-card-topline"><span>VACATION BRAIN®</span><small>CASE FILE 001</small></div>
          <div class="vb-score-label">Vacation Brain Score</div>
          <div class="vb-score">742</div>
          <div class="vb-severity"><span></span> Severe Vacation Brain</div>
          <p>At this point your employer should probably be concerned.</p>
          <div class="vb-prescription">
            <small>PRESCRIPTION</small>
            <strong>5–7 nights somewhere warm.</strong>
            <span>Direct flight preferred. No scheduled activities before 10 AM.</span>
          </div>
        </article>
        <div class="vb-sticker vb-sticker-one">DAY 38<br><span>thinking about vacation</span></div>
        <div class="vb-sticker vb-sticker-two">LESS ROUTINE.<br>MORE HERE.</div>
      </div>
    </div>
  </section>

  <section class="vb-merch-section" id="off-duty-goods">
    <div class="shell">
      <div class="vb-section-heading">
        <div>
          <span class="vb-kicker">Vacation Brain® Off Duty Goods</span>
          <h2>Dress for the trip you are mentally already on.</h2>
        </div>
        <div class="vb-section-action">
          <p>Palm Springs Drop 001 + Vacation Brain essentials.</p>
          <a href="<?=e(app_url('shop.php'))?>">Shop everything →</a>
        </div>
      </div>

      <div class="vb-product-grid">
        <?php foreach ($merch as $product):
          $productHref = app_url('shop-product.php?slug='.urlencode((string)$product['slug']));
          $isTwoSided = !empty($product['secondary_image_url']);
        ?>
          <article class="vb-product-card">
            <a class="vb-product-media" href="<?=e($productHref)?>" aria-label="View <?=e((string)$product['name'])?>">
              <img class="vb-product-primary" src="<?=e((string)$product['image_url'])?>" alt="<?=e((string)$product['name'].($isTwoSided?' — front':''))?>">
              <?php if ($isTwoSided): ?>
                <span class="vb-view-label vb-view-front">FRONT</span>
                <span class="vb-secondary-wrap">
                  <img src="<?=e((string)$product['secondary_image_url'])?>" alt="<?=e((string)$product['name'].' — back')?>">
                  <small>BACK</small>
                </span>
              <?php endif; ?>
            </a>
            <div class="vb-product-copy">
              <div class="vb-product-meta"><span><?=e(strtoupper((string)$product['product_type']))?></span><?php if($isTwoSided):?><span>FRONT + BACK</span><?php endif;?></div>
              <h3><a href="<?=e($productHref)?>"><?=e((string)$product['name'])?></a></h3>
              <p><?=e((string)$product['short_description'])?></p>
              <div class="vb-product-bottom">
                <strong>$<?=number_format((float)$product['price'],2)?></strong>
                <a href="<?=e($productHref)?>">View product →</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="vb-how-section">
    <div class="shell">
      <div class="vb-section-heading compact">
        <div><span class="vb-kicker">How bad is it?</span><h2>Ten swipes. One diagnosis. Several bad ideas.</h2></div>
      </div>
      <div class="vb-how-grid">
        <article><span>01</span><h3>Answer suspiciously revealing questions.</h3><p>Pool or beach. Direct flight or financial responsibility. Structured itinerary or absolutely not.</p></article>
        <article><span>02</span><h3>Receive your Vacation Brain score.</h3><p>Your answers become a travel preference profile with a very official-looking completely non-medical diagnosis.</p></article>
        <article><span>03</span><h3>Let the condition get worse.</h3><p>Build dream trips, explore destinations, generate fake vacation evidence, and keep feeding the Brain.</p></article>
      </div>
      <div class="vb-final-cta">
        <div><small>PROGNOSIS</small><h2>You are probably not going to stop thinking about vacation.</h2></div>
        <a class="vb-button vb-button-primary" href="<?=e(app_url('diagnosis.php'))?>">Diagnose Me →</a>
      </div>
    </div>
  </section>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
