<?php
require __DIR__.'/app/bootstrap.php';
require_auth();
$pdo = db();

if (!db_table_exists('merch_catalog_products')) {
    $title = 'Shop — Vacation Brain';
    require __DIR__.'/partials/header.php'; ?>
    <section class="dashboard"><div class="shell"><div class="dashboard-card upgrade-required-card"><div class="eyebrow">Vacation Brain Shop</div><h1>Shop is almost ready.</h1><p class="muted">The Shop catalog needs the latest Vacation Brain database upgrade before it can load.</p><?php if(is_admin()):?><a class="button primary" href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?><p class="microcopy">An administrator needs to run the pending system upgrade.</p><?php endif;?></div></div></section>
    <?php require __DIR__.'/partials/footer.php'; exit;
}

$sampleClause = (db_column_exists('merch_catalog_products','is_sample') && !sample_data_enabled()) ? ' AND is_sample=0' : '';
$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$sql = "SELECT * FROM merch_catalog_products WHERE status='active'".$sampleClause;
$args = [];
if ($q !== '') {
    $sql .= ' AND (name LIKE ? OR short_description LIKE ? OR product_type LIKE ?)';
    $like = '%'.$q.'%';
    $args = [$like,$like,$like];
}
if ($type !== '') {
    $sql .= ' AND product_type=?';
    $args[] = $type;
}
$sql .= ' ORDER BY featured DESC,sort_order ASC,name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

$cart = $_SESSION['shop_cart'] ?? [];
$cartCount = array_sum(array_map('intval', $cart));
$title = 'Shop — Vacation Brain';
require __DIR__.'/partials/header.php';
?>
<section class="dashboard"><div class="shell">
  <div class="dashboard-head">
    <div><div class="eyebrow">Vacation Brain · Off Duty Goods</div><h1>Merch</h1><p class="muted">Hoodies, trucker hats and Palm Springs goods for people whose attention has already left town.</p></div>
    <a class="button secondary small" href="<?=e(app_url('cart.php'))?>">Cart<?= $cartCount ? ' · '.$cartCount : '' ?></a>
  </div>

  <form class="dashboard-card shop-filter" method="get">
    <input class="input" type="search" name="q" value="<?=e($q)?>" placeholder="Search Vacation Brain merch…">
    <select class="input" name="type">
      <option value="">All products</option>
      <?php foreach (['shirt'=>'Tees','hoodie'=>'Hoodies','sweatshirt'=>'Crewnecks','hat'=>'Hats','patch'=>'Patches','mug'=>'Mugs','drinkware'=>'Drinkware','bag'=>'Bags','sticker'=>'Stickers'] as $key=>$label): ?>
        <option value="<?=e($key)?>" <?=$type === $key ? 'selected' : ''?>><?=e($label)?></option>
      <?php endforeach; ?>
    </select>
    <button class="button secondary small">Filter</button>
  </form>

  <div class="shop-grid">
    <?php foreach ($rows as $product): ?>
      <article class="shop-card">
        <a class="shop-media" href="<?=e(app_url('shop-product.php?slug='.urlencode($product['slug'])))?>">
          <?php if ($product['image_url']): ?><img src="<?=e(media_url((string)$product['image_url']))?>" alt="<?=e($product['name'])?>"><?php else: ?><span><?=e(strtoupper($product['product_type']))?></span><?php endif; ?>
        </a>
        <div class="shop-body">
          <div class="shop-card-meta"><div class="eyebrow"><?=e($product['product_type'])?></div><?php if (!empty($product['is_sample'])): ?><span class="sample-badge">Sample</span><?php endif; ?></div>
          <h2><a href="<?=e(app_url('shop-product.php?slug='.urlencode($product['slug'])))?>"><?=e($product['name'])?></a></h2>
          <p class="muted"><?=e($product['short_description'])?></p>
          <div class="shop-price"><strong>$<?=number_format((float)$product['price'],2)?></strong><?php if($product['compare_at_price']):?><del>$<?=number_format((float)$product['compare_at_price'],2)?></del><?php endif;?></div>
          <a class="button secondary small" href="<?=e(app_url('shop-product.php?slug='.urlencode($product['slug'])))?>">View product</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
