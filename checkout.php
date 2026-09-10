<?php
require __DIR__.'/app/bootstrap.php';$title='Checkout — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="dashboard"><div class="shell"><div class="dashboard-head"><div><div class="eyebrow">Shop</div><h1>Checkout</h1><p class="muted">The catalog and cart are ready. Payment/fulfillment will connect here when you choose the commerce provider.</p></div></div><div class="dashboard-card"><h2>Checkout shell</h2><p>Vacation Brain is not processing payments in this build yet.</p><a class="button secondary" href="<?=e(app_url('cart.php'))?>">Back to cart</a></div></div></section>
<?php require __DIR__.'/partials/footer.php'; ?>
