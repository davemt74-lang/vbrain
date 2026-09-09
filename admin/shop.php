<?php
require __DIR__.'/../app/bootstrap.php';
$adminId = require_admin();
if (!db_table_exists('merch_catalog_products')) redirect('upgrade.php');

$pdo = db();
$upload = new UploadService(dirname(__DIR__));
$success = flash('success');
$error = flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'archive' && $id) {
            $pdo->prepare('UPDATE merch_catalog_products SET status="archived" WHERE id=?')->execute([$id]);
            flash('success', 'Product archived.');
            redirect('admin/shop.php');
        }

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Product name is required.');

        $slug = trim((string)($_POST['slug'] ?? ''));
        if ($slug === '') $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        $image = trim((string)($_POST['image_url'] ?? ''));
        if (!empty($_FILES['product_image']['name'])) {
            $image = $upload->storeImage($_FILES['product_image'], $adminId, 'shop', 900, 900) ?? $image;
        }

        $secondaryImage = trim((string)($_POST['secondary_image_url'] ?? ''));
        if (!empty($_FILES['secondary_product_image']['name'])) {
            $secondaryImage = $upload->storeImage($_FILES['secondary_product_image'], $adminId, 'shop', 900, 900) ?? $secondaryImage;
        }

        $sku = trim((string)($_POST['sku'] ?? ''));
        $data = [
            $slug,
            $name,
            trim((string)($_POST['short_description'] ?? '')),
            trim((string)($_POST['description'] ?? '')),
            trim((string)($_POST['product_type'] ?? 'shirt')),
            $sku !== '' ? $sku : null,
            (float)($_POST['price'] ?? 0),
            ($_POST['compare_at_price'] ?? '') !== '' ? (float)$_POST['compare_at_price'] : null,
            $image,
            $secondaryImage !== '' ? $secondaryImage : null,
            (string)($_POST['status'] ?? 'draft'),
            isset($_POST['featured']) ? 1 : 0,
            (int)($_POST['sort_order'] ?? 0),
        ];

        if ($id) {
            $pdo->prepare('UPDATE merch_catalog_products SET slug=?,name=?,short_description=?,description=?,product_type=?,sku=?,price=?,compare_at_price=?,image_url=?,secondary_image_url=?,status=?,featured=?,sort_order=? WHERE id=?')->execute([...$data, $id]);
        } else {
            $pdo->prepare('INSERT INTO merch_catalog_products (slug,name,short_description,description,product_type,sku,price,compare_at_price,image_url,secondary_image_url,status,featured,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
        }

        flash('success', 'Product saved.');
        redirect('admin/shop.php');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('admin/shop.php');
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM merch_catalog_products WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}
$rows = $pdo->query('SELECT * FROM merch_catalog_products ORDER BY status="active" DESC,featured DESC,sort_order,name')->fetchAll();
$title = 'Merch / Shopping — Admin';
require __DIR__.'/../partials/header.php';
?>
<section class="dashboard"><div class="shell">
  <div class="dashboard-head">
    <div><div class="eyebrow">Admin · Commerce</div><h1>Merch / Shopping</h1><p class="muted">Manage the Vacation Brain merchandise catalog, including separate front and back product images.</p></div>
    <a class="button secondary small" href="<?=e(app_url('shop.php'))?>">View Shop</a>
  </div>

  <?php if ($success): ?><div class="alert success"><?=e($success)?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>

  <div class="admin-split">
    <form class="dashboard-card stack" method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="id" value="<?=(int)($edit['id'] ?? 0)?>">
      <h2><?=$edit ? 'Edit product' : 'Add product'?></h2>

      <label>Name<input class="input" name="name" value="<?=e($edit['name'] ?? '')?>" required></label>
      <div class="form-grid">
        <label>Slug<input class="input" name="slug" value="<?=e($edit['slug'] ?? '')?>"></label>
        <label>SKU<input class="input" name="sku" value="<?=e($edit['sku'] ?? '')?>"></label>
      </div>
      <div class="form-grid">
        <label>Product type<input class="input" name="product_type" value="<?=e($edit['product_type'] ?? 'shirt')?>"></label>
        <label>Price<input class="input" type="number" step="0.01" min="0" name="price" value="<?=e((string)($edit['price'] ?? 29))?>"></label>
        <label>Compare-at price<input class="input" type="number" step="0.01" min="0" name="compare_at_price" value="<?=e((string)($edit['compare_at_price'] ?? ''))?>"></label>
      </div>

      <label>Short description<textarea class="input" name="short_description" rows="3"><?=e($edit['short_description'] ?? '')?></textarea></label>
      <label>Description<textarea class="input" name="description" rows="5"><?=e($edit['description'] ?? '')?></textarea></label>

      <div class="dashboard-card" style="padding:18px;box-shadow:none">
        <h3 style="margin-top:0">Front / primary image</h3>
        <?php if (!empty($edit['image_url'])): ?><img src="<?=e($edit['image_url'])?>" alt="Current front product image" style="width:120px;height:120px;object-fit:cover;border-radius:14px;border:1px solid var(--line);margin-bottom:12px"><?php endif; ?>
        <label>Image URL<input class="input" name="image_url" value="<?=e($edit['image_url'] ?? '')?>"></label>
        <label>Or upload front image<input class="input" type="file" name="product_image" accept="image/jpeg,image/png,image/webp"></label>
      </div>

      <div class="dashboard-card" style="padding:18px;box-shadow:none">
        <h3 style="margin-top:0">Back / secondary image</h3>
        <?php if (!empty($edit['secondary_image_url'])): ?><img src="<?=e($edit['secondary_image_url'])?>" alt="Current back product image" style="width:120px;height:120px;object-fit:cover;border-radius:14px;border:1px solid var(--line);margin-bottom:12px"><?php endif; ?>
        <label>Secondary image URL<input class="input" name="secondary_image_url" value="<?=e($edit['secondary_image_url'] ?? '')?>"></label>
        <label>Or upload back image<input class="input" type="file" name="secondary_product_image" accept="image/jpeg,image/png,image/webp"></label>
        <p class="microcopy">Use this for the back of hoodies or a second product angle. Leave blank for single-image products.</p>
      </div>

      <div class="form-grid">
        <label>Status<select class="input" name="status"><?php foreach (['draft','active','archived'] as $s): ?><option value="<?=$s?>" <?=($edit['status'] ?? 'active') === $s ? 'selected' : ''?>><?=ucfirst($s)?></option><?php endforeach; ?></select></label>
        <label>Sort order<input class="input" type="number" name="sort_order" value="<?=e((string)($edit['sort_order'] ?? 0))?>"></label>
      </div>
      <label class="check-row"><input type="checkbox" name="featured" <?=!empty($edit['featured']) ? 'checked' : ''?>> Featured product</label>
      <button class="button primary">Save product</button>
    </form>

    <div class="dashboard-card">
      <h2>Catalog</h2>
      <div class="table-wrap"><table class="admin-table"><thead><tr><th>Product</th><th>Images</th><th>Price</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $product): ?><tr>
        <td><strong><?=e($product['name'])?></strong><br><small class="muted"><?=e($product['sku'] ?: $product['product_type'])?></small><?php if (!empty($product['is_sample'])): ?><br><span class="sample-badge">Sample</span><?php endif; ?></td>
        <td><?=!empty($product['secondary_image_url']) ? 'Front + Back' : (!empty($product['image_url']) ? 'Primary' : 'None')?></td>
        <td>$<?=number_format((float)$product['price'], 2)?></td>
        <td><?=e($product['status'])?></td>
        <td><a href="?edit=<?=(int)$product['id']?>">Edit</a></td>
      </tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
</div></section>
<?php require __DIR__.'/../partials/footer.php'; ?>
