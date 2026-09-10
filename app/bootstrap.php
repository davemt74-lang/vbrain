<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$configCandidates = [$rootDir . '/config.php'];

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
if ($documentRoot !== '') {
    $configCandidates[] = $documentRoot . '/config.php';
}

$envConfig = trim((string)(getenv('VACATION_BRAIN_CONFIG') ?: ''));
if ($envConfig !== '') {
    $configCandidates[] = $envConfig;
}

$configFile = null;
foreach (array_unique($configCandidates) as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $configFile = $candidate;
        break;
    }
}

if ($configFile === null) {
    error_log('Vacation Brain config.php not found/readable. Checked: ' . implode(', ', $configCandidates));
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vacation Brain configuration required</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f7f2e8;color:#123643;margin:0;padding:40px}.card{max-width:680px;margin:8vh auto;background:#fff;border:1px solid #e4ddd2;border-radius:18px;padding:30px;box-shadow:0 14px 38px rgba(20,50,60,.08)}h1{margin-top:0;font-size:28px}code{background:#f1ece3;padding:2px 6px;border-radius:6px}p{line-height:1.6}</style></head><body><div class="card"><h1>Vacation Brain configuration not found</h1><p>This is an existing installation, so you do not need to run the installer.</p><p>Place a readable <code>config.php</code> in the same web root as <code>index.php</code>. If your hosting setup uses a different document root, Vacation Brain will also check that document root automatically.</p><p>After moving the existing config file, reload this page.</p></div></body></html>';
    exit;
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Vacation Brain config.php must return a PHP array.');
}

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'vacation_brain_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Services/ScoreService.php';
require_once __DIR__ . '/Services/DiagnosisService.php';
require_once __DIR__ . '/Services/DiagnosisPersistenceService.php';
require_once __DIR__ . '/Services/SwipeService.php';
require_once __DIR__ . '/Services/AchievementService.php';
require_once __DIR__ . '/Services/UserService.php';
require_once __DIR__ . '/Services/UploadService.php';
require_once __DIR__ . '/Services/CheckinService.php';
require_once __DIR__ . '/Services/SubstitutionService.php';
require_once __DIR__ . '/Services/ContentFactoryService.php';
require_once __DIR__ . '/Services/AiProviderService.php';
require_once __DIR__ . '/Services/DestinationPromptService.php';
require_once __DIR__ . '/Services/DestinationAiService.php';
require_once __DIR__ . '/Services/DestinationRecommendationService.php';
require_once __DIR__ . '/Services/DestinationResearchService.php';
require_once __DIR__ . '/Services/FunContentService.php';
require_once __DIR__ . '/Services/VacationBreakService.php';
require_once __DIR__ . '/Services/MerchService.php';
require_once __DIR__ . '/Services/VacationProfileService.php';
require_once __DIR__ . '/Services/VacationImageProfileService.php';
require_once __DIR__ . '/Services/VacationPhotoPolicyService.php';
require_once __DIR__ . '/Services/VacationPhotoService.php';
require_once __DIR__ . '/Services/VacationPhotoGalleryService.php';
require_once __DIR__ . '/Services/VacationPhotoAgentService.php';
require_once __DIR__ . '/Services/DreamService.php';
require_once __DIR__ . '/Services/WeatherEnvyService.php';
require_once __DIR__ . '/Services/RoastService.php';
require_once __DIR__ . '/Services/VacationAgentService.php';
require_once __DIR__ . '/Services/NotificationService.php';
require_once __DIR__ . '/Services/TravelMatchService.php';
require_once __DIR__ . '/Services/TravelMessageService.php';
require_once __DIR__ . '/Services/MatchEngagementService.php';
