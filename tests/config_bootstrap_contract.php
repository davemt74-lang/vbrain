<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/app/bootstrap.php';
$source = file_get_contents($path);
if ($source === false) {
    fwrite(STDERR, "Could not read app/bootstrap.php\n");
    exit(1);
}

$required = [
    "dirname(__DIR__) . '/config.php'",
    "DOCUMENT_ROOT",
    "VACATION_BRAIN_CONFIG",
    "is_readable",
    "http_response_code(500)",
    "Vacation Brain configuration not found",
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing bootstrap config behavior: {$needle}\n");
        exit(1);
    }
}

if (strpos($source, "header('Location: '") !== false && strpos($source, 'install.php') !== false) {
    fwrite(STDERR, "Bootstrap must not redirect an existing deployment to install.php when config.php is missing.\n");
    exit(1);
}

echo "Config bootstrap contract OK\n";
