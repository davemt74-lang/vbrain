<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = file_get_contents($root . '/partials/header.php');
$shell = file_get_contents($root . '/partials/shell-actions.php');
$css = file_get_contents($root . '/assets/shell-commerce.css');
$landingHeaderCss = file_get_contents($root . '/assets/landing-header-fixes.css');

if ($header === false || $shell === false || $css === false || $landingHeaderCss === false) {
    fwrite(STDERR, "Could not read public header files.\n");
    exit(1);
}

$publicHeaderStart = strpos($header, '<header class="site-header">');
if ($publicHeaderStart === false) {
    fwrite(STDERR, "Public site header not found.\n");
    exit(1);
}
$publicHeader = substr($header, $publicHeaderStart);
if (strpos($publicHeader, 'login.php') !== false) {
    fwrite(STDERR, "Public login must not remain inside the primary nav.\n");
    exit(1);
}

$authNotificationGuard = '<?php if($shellUser):?>';
$notificationPos = strpos($shell, 'data-notification-toggle');
$authGuardPos = strpos($shell, $authNotificationGuard);
$authGuardEnd = strpos($shell, '<?php endif;?>', $authGuardPos === false ? 0 : $authGuardPos);
$cartPos = strpos($shell, 'data-cart-toggle');
$loginPos = strpos($shell, 'class="vb-shell-login"');
if ($authGuardPos === false || $notificationPos === false || $authGuardEnd === false || !($authGuardPos < $notificationPos && $notificationPos < $authGuardEnd && $authGuardEnd < $cartPos)) {
    fwrite(STDERR, "Notification control must render only for authenticated users.\n");
    exit(1);
}
if ($cartPos === false || $loginPos === false || !($cartPos < $loginPos)) {
    fwrite(STDERR, "Public shell actions must keep cart before login.\n");
    exit(1);
}
if (strpos($shell, '<?php if(!$shellUser):?>') === false) {
    fwrite(STDERR, "Shell login must be public-only.\n");
    exit(1);
}
if (strpos($shell, "assets/landing-header-fixes.css") === false || strpos($landingHeaderCss, '.vbe-nav-cta{display:none!important}') === false) {
    fwrite(STDERR, "Landing header diagnosis CTA must be removed from the visible top navigation.\n");
    exit(1);
}
if (strpos($css, '.vb-shell-login{') === false) {
    fwrite(STDERR, "Public shell login styling is missing.\n");
    exit(1);
}

echo "Public header contract passed.\n";
