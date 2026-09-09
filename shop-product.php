<?php
require __DIR__.'/app/bootstrap.php';
require_auth();

$pdo = db();
$slug = (string)($_GET['slug'] ?? '');
$sampleClause = (db_column_exists('merch_catalog_products','is_sample') && !sample_data_enabled()) ? ' AND is_sample=0' : '';
$stmt = $pdo->prepare("SELECT * FROM merch_catalog_products WHERE slug=? AND status='active'".$sampleClause.' LIMIT 1');
$stmt->execute([$slug]);
$p = $stmt->fetch();
if (!$p) {
    http_response_code(404);
    exit('Product not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $qty = max(1, min(10, (int)($_POST['qty'] ?? 1)));
    $_SESSION['shop_cart'][$p['id']] = ($_SESSION['shop_cart'][$p['id']] ?? 0) + $qty;
    flash('success', 'Added to cart.');
    redirect('cart.php');
}

$meta = json_decode((string)($p['metadata_json'] ?? ''), true) ?: [];
$images = [];
if (!empty($p['image_url'])) {
    $images[] = ['url' => (string)$p['image_url'], 'label' => !empty($p['secondary_image_url']) ? 'Front' : 'Product'];
}
if (!empty($p['secondary_image_url'])) {
    $images[] = ['url' => (string)$p['secondary_image_url'], 'label' => 'Back'];
}

$title = $p['name'].' — Vacation Brain';
require __DIR__.'/partials/header.php';
?>
<section class="dashboard">
  <div class="shell">
    <a class="link-arrow" href="<?=e(app_url('shop.php'))?>">← Back to Shop</a>
    <div class="product-detail">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;align-self:start">
        <?php if ($images): ?>
          <?php foreach ($images as $image): ?>
            <figure class="product-detail-media" style="margin:0;position:relative;overflow:hidden">
              <img src="<?=e(media_url((string)$image['url']))?>" alt="<?=e($p['name'].' — '.$image['label'])?>" style="display:block;width:100%;height:100%;object-fit:cover">
              <?php if (count($images) > 1): ?>
                <figcaption style="position:absolute;left:14px;bottom:14px;background:rgba(255,255,255,.92);border:1px solid var(--line);border-radius:999px;padding:7px 11px;font-size:12px;font-weight:900"><?=e($image['label'])?></figcaption>
              <?php endif; ?>
            </figure>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="product-detail-media"><span><?=e(strtoupper($p['product_type']))?></span></div>
        <?php endif; ?>
      </div>

      <div class="product-detail-copy">
        <div class="product-detail-labels">
          <div class="eyebrow"><?=e($p['product_type'])?></div>
          <?php if (!empty($p['is_sample'])): ?><span class="sample-badge">Sample</span><?php endif; ?>
        </div>
        <h1><?=e($p['name'])?></h1>
        <?php if (!empty($meta['collection'])): ?><p class="microcopy" style="margin:0 0 10px;text-transform:uppercase;letter-spacing:.08em;font-weight:900"><?=e((string)$meta['collection'])?></p><?php endif; ?>
        <div class="shop-price large"><strong>$<?=number_format((float)$p['price'],2)?></strong><?php if(!empty($p['compare_at_price'])):?><del>$<?=number_format((float)$p['compare_at_price'],2)?></del><?php endif;?></div>
        <p><?=nl2br(e($p['description'] ?: $p['short_description']))?></p>

        <?php if (!empty($meta['features']) && is_array($meta['features'])): ?>
          <ul class="prescription-list" style="margin-bottom:22px">
            <?php foreach ($meta['features'] as $feature): ?><li><?=e((string)$feature)?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($meta['sizes']) || !empty($meta['colors'])): ?>
          <div class="product-options-summary">
            <?php if (!empty($meta['sizes'])): ?><span><strong>Sizes</strong><?=e(is_array($meta['sizes']) ? implode(', ', $meta['sizes']) : (string)$meta['sizes'])?></span><?php endif; ?>
            <?php if (!empty($meta['colors'])): ?><span><strong>Colors</strong><?=e(is_array($meta['colors']) ? implode(', ', $meta['colors']) : (string)$meta['colors'])?></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="stack">
          <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
          <label>Quantity<input class="input" type="number" min="1" max="10" name="qty" value="1"></label>
          <button class="button primary">Add to cart</button>
        </form>
      </div>
    </div>
  </div>
</section>
<?php require __DIR__.'/partials/footer.php'; ?>
