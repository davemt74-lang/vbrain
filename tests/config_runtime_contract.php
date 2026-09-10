<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/app/bootstrap.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Could not read app/bootstrap.php\n");
    exit(1);
}

$required = [
    "\$configFile = dirname(__DIR__) . '/config.php'",
    'is_file($configFile)',
    'is_readable($configFile)',
    '$config = require $configFile',
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing direct config behavior: {$needle}\n");
        exit(1);
    }
}

$forbidden = [
    'DOCUMENT_ROOT',
    'VACATION_BRAIN_CONFIG',
    "header('Location: '",
];

foreach ($forbidden as $needle) {
    if (strpos($source, $needle) !== false) {
        fwrite(STDERR, "Unexpected bootstrap behavior remains: {$needle}\n");
        exit(1);
    }
}

echo "Config runtime contract OK\n";
