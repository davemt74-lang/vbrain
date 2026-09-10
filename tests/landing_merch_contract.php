<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$css = file_get_contents($root . '/assets/landing-merch-fix.css');

if ($index === false || $css === false) {
    fwrite(STDERR, "Could not read landing merch files.\n");
    exit(1);
}

$requiredIndex = [
    "['assets/landing.css', 'assets/landing-merch-fix.css']",
    '[$dbPrimary, $preferredPrimary, $fallbackPrimary]',
    '[$dbSecondary, $knownSecondary]',
    'vb-product-view--swap',
    'vb-product-image--front',
    'vb-product-image--back',
];
foreach ($requiredIndex as $needle) {
    if (strpos($index, $needle) === false) {
        fwrite(STDERR, "Missing landing merch behavior: {$needle}\n");
        exit(1);
    }
}

if (strpos($index, '<div class="vb-product-view"><span>Front</span>') !== false) {
    fwrite(STDERR, "Landing page must not render front/back hoodie cards side by side.\n");
    exit(1);
}

$requiredCss = [
    '.vb-product--hoodie .vb-product-media{display:block!important',
    '.vb-product-image--back{opacity:0',
    '.has-secondary:hover .vb-product-image--front{opacity:0',
    '.has-secondary:hover .vb-product-image--back{opacity:1',
];
foreach ($requiredCss as $needle) {
    if (strpos($css, $needle) === false) {
        fwrite(STDERR, "Missing landing merch CSS behavior: {$needle}\n");
        exit(1);
    }
}

echo "Landing merch contract passed.\n";
