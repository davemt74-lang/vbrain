<?php
$title = $title ?? 'Vacation Brain';
$user = current_user();
$current = basename($_SERVER['PHP_SELF'] ?? '');
$scriptPath = str_replace('\\','/', $_SERVER['PHP_SELF'] ?? '');
$inAdmin = str_contains($scriptPath, '/admin/') || $current === 'upgrade.php';
$matchUnread = 0; $notificationUnread = 0;
$destinationAccountsReady = false; $destinationDashboardAvailable = false;
$siteName = site_setting('brand.site_name','Vacation Brain') ?: 'Vacation Brain';
$siteLogo = site_setting('brand.logo_url','') ?: '';
$pwaEnabled = site_setting_bool('pwa.enabled');
$pwaTheme = site_setting('pwa.theme_color','#ffffff') ?: '#ffffff';
$pwaIcon = site_setting('pwa.icon_url','') ?: '';
$pwaSplash = site_setting('pwa.splash_url','') ?: '';
$metaDescription = $metaDescription ?? 'Vacation Brain — find out how badly you need a vacation, build your vacation profile, and turn daydreaming into your next trip.';
$ogTitle = $ogTitle ?? $title;
$ogDescription = $ogDescription ?? $metaDescription;
$ogImage = trim((string)($ogImage ?? ''));
$canonicalUrl = trim((string)($canonicalUrl ?? ''));
$pageStyles = is_array($pageStyles ?? null) ? $pageStyles : [];
if ($user) {
    try { $matchUnread = (new TravelMessageService(db()))->unreadCount((int)$user['id']); } catch (Throwable $e) { $matchUnread = 0; }
    try { $notificationUnread = (new NotificationService(db()))->unreadCount((int)$user['id']); } catch (Throwable $e) { $notificationUnread = 0; }
    try { $destinationOwnerNav = new DestinationOwnerService(db()); $destinationAccountsReady=$destinationOwnerNav->ready(); $destinationDashboardAvailable=$destinationOwnerNav->canUseDashboard((int)$user['id']); } catch (Throwable $e) { $destinationAccountsReady=false; $destinationDashboardAvailable=false; }
}
function nav_active(array $files): string { global $current; return in_array($current,$files,true)?'active':''; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?=e($metaDescription)?>">
<meta property="og:title" content="<?=e($ogTitle)?>">
<meta property="og:description" content="<?=e($ogDescription)?>">
<meta property="og:type" content="website">
<?php if($ogImage):?><meta property="og:image" content="<?=e($ogImage)?>"><?php endif;?>
<?php if($canonicalUrl):?><meta property="og:url" content="<?=e($canonicalUrl)?>"><link rel="canonical" href="<?=e($canonicalUrl)?>"><?php endif;?>
<meta name="twitter:card" content="<?=$ogImage?'summary_large_image':'summary'?>">
<meta name="twitter:title" content="<?=e($ogTitle)?>">
<meta name="twitter:description" content="<?=e($ogDescription)?>">
<?php if($ogImage):?><meta name="twitter:image" content="<?=e($ogImage)?>"><?php endif;?>
<meta name="theme-color" content="<?=e($pwaTheme)?>">
<?php if($pwaEnabled): ?><link rel="manifest" href="<?=e(app_url('pwa-manifest.php'))?>"><?php if($pwaIcon):?><link rel="apple-touch-icon" href="<?=e($pwaIcon)?>"><?php endif;?><?php if($pwaSplash):?><link rel="apple-touch-startup-image" href="<?=e($pwaSplash)?>"><?php endif;?><?php endif;?>
<link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
<?php foreach ($pageStyles as $pageStyle): $pageStyle = trim((string)$pageStyle); if ($pageStyle === '') continue; ?><link rel="stylesheet" href="<?=e(app_url($pageStyle))?>"><?php endforeach; ?>
</head>
<body data-page="<?= e($current) ?>" class="<?= $user?'logged-in':'' ?> <?= $inAdmin?'admin-mode':'' ?>">
<div class="travel-match-entry-loader" data-travel-match-entry-loader aria-hidden="true">
  <div class="travel-match-entry-loader-card" role="status" aria-live="polite">
    <div class="travel-match-loader-mark" aria-hidden="true"><span class="loader-orbit"></span><span class="loader-brain">VB</span></div>
    <span class="eyebrow">Travel Matching</span><h2>Consulting your Vacation Brain…</h2>
    <p data-travel-match-loader-copy>Looking for people whose vacation opinions are worth investigating.</p>
    <div class="travel-match-loader-bars" aria-hidden="true"><i></i><i></i><i></i></div>
  </div>
</div>
<?php if($current==='matching.php'):?><script>(function(){var l=document.querySelector('[data-travel-match-entry-loader]');if(l){l.classList.add('open','initial');l.setAttribute('aria-hidden','false');}})();</script><?php endif;?>
<?php if($pwaEnabled): ?><script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register(<?=json_encode(app_url('service-worker.php'))?>).catch(()=>{}));}</script><?php endif; ?>
<?php if($user): ?>
<button class="sidebar-mobile-toggle" type="button" data-sidebar-toggle aria-label="Open navigation">☰</button>
<div class="sidebar-backdrop" data-sidebar-backdrop></div>
<aside class="app-sidebar" data-app-sidebar>
  <div class="sidebar-brand-row"><a class="brand sidebar-brand" href="<?=e(app_url('today.php'))?>"><?php if($siteLogo):?><img class="brand-logo-image" src="<?=e($siteLogo)?>" alt="<?=e($siteName)?>"><?php else:?><span><?=e($siteName)?></span><?php endif;?></a><button type="button" class="sidebar-close" data-sidebar-close aria-label="Close navigation">×</button></div>
  <nav class="sidebar-nav" aria-label="Vacation Brain">
    <?php if($inAdmin && is_admin()): ?>
      <span class="sidebar-section-label">Admin</span>
      <a class="<?=nav_active(['index.php'])?>" href="<?=e(app_url('admin/index.php'))?>"><span>⌂</span>Overview</a>
      <a class="<?=nav_active(['users.php'])?>" href="<?=e(app_url('admin/users.php'))?>"><span>◎</span>Users</a>
      <a class="<?=nav_active(['destinations.php','destination-prompts.php'])?>" href="<?=e(app_url('admin/destinations.php'))?>"><span>⌖</span>Destinations</a>
      <a class="<?=nav_active(['destination-ai-create.php'])?>" href="<?=e(app_url('admin/destination-ai-create.php'))?>"><span>✦</span>AI Create Destination</a>
      <a class="<?=nav_active(['destination-reports.php'])?>" href="<?=e(app_url('admin/destination-reports.php'))?>"><span>◫</span>Destination Research</a>
      <a class="<?=nav_active(['vacation-photos.php'])?>" href="<?=e(app_url('admin/vacation-photos.php'))?>"><span>▧</span>Vacation Yourself</a>
      <a class="<?=nav_active(['shop.php'])?>" href="<?=e(app_url('admin/shop.php'))?>"><span>▣</span>Merch / Shopping</a>
      <a class="<?=nav_active(['sample-data.php'])?>" href="<?=e(app_url('admin/sample-data.php'))?>"><span>◉</span>Sample Data</a>
      <a class="<?=nav_active(['brand-pwa.php'])?>" href="<?=e(app_url('admin/brand-pwa.php'))?>"><span>◈</span>Brand & PWA</a>
      <a class="<?=nav_active(['content.php'])?>" href="<?=e(app_url('admin/content.php'))?>"><span>▤</span>Content Library</a>
      <a class="<?=nav_active(['ai-content.php'])?>" href="<?=e(app_url('admin/ai-content.php'))?>"><span>✦</span>AI Content Factory</a>
      <a class="<?=nav_active(['review-content.php'])?>" href="<?=e(app_url('admin/review-content.php'))?>"><span>✓</span>Review Queue</a>
      <a class="<?=nav_active(['places.php'])?>" href="<?=e(app_url('admin/places.php'))?>"><span>⌖</span>Local Guide</a>
      <a class="<?=nav_active(['assessments.php'])?>" href="<?=e(app_url('admin/assessments.php'))?>"><span>◇</span>Assessments</a>
      <a class="<?=nav_active(['match-reports.php'])?>" href="<?=e(app_url('admin/match-reports.php'))?>"><span>!</span>Match Safety</a>
      <a class="<?=nav_active(['ai-settings.php'])?>" href="<?=e(app_url('admin/ai-settings.php'))?>"><span>✦</span>AI / API Keys</a>
      <a class="<?=nav_active(['upgrade.php'])?>" href="<?=e(app_url('upgrade.php'))?>"><span>↻</span>System Upgrade</a>
      <div class="sidebar-divider"></div><a href="<?=e(app_url('today.php'))?>"><span>←</span>Back to Vacation Brain</a>
    <?php else: ?>
      <span class="sidebar-section-label">Vacation Brain</span>
      <a class="<?=nav_active(['today.php'])?>" href="<?=e(app_url('today.php'))?>"><span>⌂</span>Dashboard</a>
      <a class="<?=nav_active(['dream.php','dream-trip.php'])?>" href="<?=e(app_url('dream.php'))?>"><span>☁</span>Plan Trip</a>
      <a class="<?=nav_active(['destinations.php'])?>" href="<?=e(app_url('destinations.php'))?>"><span>⌖</span>Destinations</a>
      <?php if($destinationDashboardAvailable):?><a class="<?=nav_active(['destination-dashboard.php','destination-edit.php'])?>" href="<?=e(app_url('destination-dashboard.php'))?>"><span>▤</span>Destination Dashboard</a><?php endif;?>
      <a class="<?=nav_active(['photos.php','vacation-yourself.php','vacation-gallery.php'])?>" href="<?=e(app_url('photos.php'))?>"><span>▧</span>Photos</a>
      <a class="<?=nav_active(['shop.php','shop-product.php','cart.php','checkout.php','merch.php'])?>" href="<?=e(app_url('shop.php'))?>"><span>▣</span>Shop</a>
      <span class="sidebar-section-label second">Travel Matching</span>
      <a class="<?=nav_active(['matching.php','match-user.php','compare.php'])?>" href="<?=e(app_url('matching.php'))?>"><span>♥</span>Discover</a>
      <a class="<?=nav_active(['match-contacts.php','match-chat.php'])?>" href="<?=e(app_url('match-contacts.php'))?>"><span>✉</span>Matches & Messages<?php if($matchUnread>0):?><b class="nav-unread"><?=$matchUnread>99?'99+':$matchUnread?></b><?php endif;?></a>
      <a class="<?=nav_active(['match-space.php','match-game.php'])?>" href="<?=e(app_url('match-contacts.php'))?>"><span>✣</span>Match Space</a>
      <span class="sidebar-section-label second">Account</span>
      <a class="<?=nav_active(['notifications.php'])?>" href="<?=e(app_url('notifications.php'))?>"><span>◌</span>Alerts<?php if($notificationUnread>0):?><b class="nav-unread"><?=$notificationUnread>99?'99+':$notificationUnread?></b><?php endif;?></a>
      <a class="<?=nav_active(['profile.php','merch.php','share.php'])?>" href="<?=e(app_url('profile.php'))?>"><span>◉</span>My Vacation Brain</a>
      <?php if(is_assessor() && !is_admin()):?><a href="<?=e(app_url('admin/assessments.php'))?>"><span>◇</span>Assessments</a><?php endif;?>
    <?php endif; ?>
  </nav>
  <div class="sidebar-user-wrap">
    <button class="sidebar-user-button" type="button" data-user-menu-toggle aria-expanded="false">
      <span class="sidebar-avatar"><?php if(!empty($user['avatar_url'])):?><img src="<?=e($user['avatar_url'])?>" alt=""><?php else:?><b><?=e(user_initials($user['display_name']??''))?></b><?php endif;?></span>
      <span class="sidebar-user-copy"><strong><?=e($user['display_name']?:'Vacation Brain User')?></strong><small><?=e($user['email'])?></small></span><span class="user-menu-caret">⋯</span>
    </button>
    <div class="sidebar-user-menu" data-user-menu>
      <a href="<?=e(app_url('profile.php'))?>">My Vacation Brain</a>
      <a href="<?=e(app_url('photos.php'))?>">Photos</a>
      <?php if($destinationDashboardAvailable):?><a href="<?=e(app_url('destination-dashboard.php'))?>">Destination Dashboard</a><?php endif;?>
      <?php if($destinationAccountsReady):?><a href="<?=e(app_url('destination-claim.php'))?>">Claim a Destination</a><?php endif;?>
      <a href="<?=e(app_url('account.php'))?>">Account & Settings</a>
      <a href="<?=e(app_url('match-profile.php'))?>">Travel Match Profile</a>
      <a href="<?=e(app_url('notification-settings.php'))?>">Notification Settings</a>
      <?php if(can_show_admin_entry()):?><a href="<?=e(app_url(is_admin()?'admin/index.php':'admin-access.php'))?>">Admin Dashboard</a><?php endif;?>
      <div class="menu-rule"></div><a href="<?=e(app_url('logout.php'))?>">Log out</a>
    </div>
  </div>
</aside>
<main class="app-main">
<?php else: ?>
<header class="site-header"><div class="shell nav-shell"><a class="brand" href="<?=e(app_url('index.php'))?>"><?php if($siteLogo):?><img class="brand-logo-image" src="<?=e($siteLogo)?>" alt="<?=e($siteName)?>"><?php else:?><span><?=e($siteName)?></span><?php endif;?></a><nav class="nav-actions" aria-label="Primary"><a href="<?=e(app_url('diagnosis.php'))?>">Self-Diagnosis</a><a href="<?=e(app_url('professional-assessment.php'))?>">Professional Assessment</a><a href="<?=e(app_url('shop.php'))?>">Shop</a></nav></div></header><main>
<?php endif; ?>
