<?php
$shellUser = current_user();
$shellCartCount = 0;
foreach ((array)($_SESSION['shop_cart'] ?? []) as $qty) $shellCartCount += max(0, (int)$qty);
$shellNotificationCount = 0;
if ($shellUser) {
    try { $shellNotificationCount = (new NotificationService(db()))->unreadCount((int)$shellUser['id']); } catch (Throwable $e) { $shellNotificationCount = 0; }
}
?>
<div class="vb-shell-actions" data-shell-actions data-shell-api="<?=e(app_url('api/shell-state.php'))?>" data-shell-csrf="<?=e(csrf_token())?>" data-shell-auth="<?=$shellUser?'1':'0'?>">
  <div class="vb-notification-wrap" data-notification-wrap>
    <button class="vb-shell-icon" type="button" data-notification-toggle aria-label="Notifications" aria-expanded="false">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
      <span class="vb-shell-badge <?=$shellNotificationCount===0?'is-zero':''?>" data-notification-count><?=$shellNotificationCount>99?'99+':$shellNotificationCount?></span>
    </button>
    <div class="vb-notification-menu" data-notification-menu aria-hidden="true">
      <div class="vb-notification-head"><strong>Notifications</strong><button type="button" data-notification-read-all <?=$shellUser?'':'hidden'?>>Mark all read</button></div>
      <div class="vb-notification-list" data-notification-list><div class="vb-notification-empty">Loading…</div></div>
      <?php if($shellUser):?><div class="vb-notification-foot"><a href="<?=e(app_url('notifications.php'))?>">View all notifications →</a></div><?php endif;?>
    </div>
  </div>

  <button class="vb-shell-icon" type="button" data-cart-toggle aria-label="Shopping cart" aria-expanded="false">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L20 8H7"></path><circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle></svg>
    <span class="vb-shell-badge <?=$shellCartCount===0?'is-zero':''?>" data-cart-count><?=$shellCartCount>99?'99+':$shellCartCount?></span>
  </button>

  <?php if(!$shellUser):?>
    <a class="vb-shell-login" href="<?=e(app_url('login.php'))?>">Log in</a>
  <?php endif;?>
</div>

<div class="vb-shell-backdrop" data-shell-backdrop></div>
<aside class="vb-cart-drawer" data-cart-drawer aria-hidden="true" aria-label="Shopping cart">
  <div class="vb-cart-head"><strong>Your Cart</strong><button class="vb-cart-close" type="button" data-cart-close aria-label="Close cart">×</button></div>
  <div class="vb-cart-body" data-cart-body><div class="vb-cart-empty">Loading your cart…</div></div>
  <div class="vb-cart-foot">
    <div class="vb-cart-subtotal"><span>Subtotal</span><strong data-cart-subtotal>$0.00</strong></div>
    <a class="vb-cart-checkout" data-cart-checkout href="<?=e(app_url('checkout.php'))?>">Continue to Checkout</a>
    <a class="vb-cart-link" href="<?=e(app_url('cart.php'))?>">View full cart</a>
  </div>
</aside>
