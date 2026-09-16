<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
if ($index === false) {
    fwrite(STDERR, "Could not read landing page.\n");
    exit(1);
}

// Destination-guide landing introduced in the public homepage redesign.
// Keep the contract focused on the current user-facing behavior while retaining
// compatibility with the previous editorial and legacy merch layouts.
if (strpos($index, 'class="vbg-page"') !== false) {
    $guideCss = $root . '/assets/landing-editorial.css';
    if (!is_file($guideCss)) {
        fwrite(STDERR, "Destination-guide landing stylesheet is missing.\n");
        exit(1);
    }

    $requiredIndex = [
        "['assets/landing-editorial.css']",
        '/assets/landing-guide/',
        'hero-destination-guide.webp',
        'guide-destinations.webp',
        'guide-trips.webp',
        'guide-activities.webp',
        'guide-travel-guides.webp',
        'getaway-multiday.webp',
        'getaway-weekend.webp',
        'getaway-daytrip.webp',
        'phone-travel-companion.webp',
        'footer-travel-guide.webp',
        'Where do you want to go?',
        'name="q"',
        'name="date"',
        'name="travelers"',
        'Popular Getaways',
        'Multi-Day',
        'Weekend',
        'Day Trip',
        'Explore by what you love',
        'Your personal',
        'travel companion',
        'Real destinations.',
        "app_url('destinations.php')",
        "app_url('agent.php')",
    ];
    foreach ($requiredIndex as $needle) {
        if (strpos($index, $needle) === false) {
            fwrite(STDERR, "Missing destination-guide landing behavior: {$needle}\n");
            exit(1);
        }
    }

    $css = file_get_contents($guideCss);
    if ($css === false) {
        fwrite(STDERR, "Could not read destination-guide landing stylesheet.\n");
        exit(1);
    }

    $requiredCss = [
        '.vbg-header',
        '.vbg-hero',
        '.vbg-search',
        '.vbg-guide-grid',
        '.vbg-getaway-grid',
        '.vbg-activity-grid',
        '.vbg-companion',
        '.vbg-footer-hero',
        '@media (max-width:760px)',
    ];
    foreach ($requiredCss as $needle) {
        if (strpos($css, $needle) === false) {
            fwrite(STDERR, "Missing destination-guide CSS behavior: {$needle}\n");
            exit(1);
        }
    }

    echo "Destination-guide landing contract passed.\n";
    exit(0);
}

// Previous editorial landing contract.
if (strpos($index, "assets/landing-editorial.css") !== false) {
    $editorialCss = $root . '/assets/landing-editorial.css';
    if (!is_file($editorialCss)) {
        fwrite(STDERR, "Editorial landing stylesheet is missing.\n");
        exit(1);
    }

    $requiredIndex = [
        "['assets/landing-editorial.css']",
        'hero-sunset-coast.webp',
        'agent-getaway.webp',
        'destination_catalog',
        'Trips worth acting on.',
        'Take the Vacation Brain Diagnosis',
        'Talk to the Travel AI Agent',
        'destination.php?destination_id=',
    ];
    foreach ($requiredIndex as $needle) {
        if (strpos($index, $needle) === false) {
            fwrite(STDERR, "Missing editorial landing behavior: {$needle}\n");
            exit(1);
        }
    }

    echo "Editorial landing contract passed.\n";
    exit(0);
}

$css = file_get_contents($root . '/assets/landing-merch-fix.css');
if ($css === false) {
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
