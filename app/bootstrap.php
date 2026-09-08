<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    $scriptDir = str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (basename($scriptDir) === 'admin') {
        $scriptDir = dirname($scriptDir);
    }
    $installUrl = rtrim($scriptDir, '/') . '/install.php';
    header('Location: ' . ($installUrl === '/install.php' ? '/install.php' : $installUrl));
    exit;
}

$config = require $configFile;
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
require_once __DIR__ . '/Services/SwipeService.php';
require_once __DIR__ . '/Services/AchievementService.php';
require_once __DIR__ . '/Services/UserService.php';
require_once __DIR__ . '/Services/UploadService.php';
require_once __DIR__ . '/Services/CheckinService.php';
require_once __DIR__ . '/Services/SubstitutionService.php';
require_once __DIR__ . '/Services/ContentFactoryService.php';
require_once __DIR__ . '/Services/AiProviderService.php';
require_once __DIR__ . '/Services/VacationPhotoService.php';
require_once __DIR__ . '/Services/DestinationResearchService.php';
require_once __DIR__ . '/Services/FunContentService.php';
require_once __DIR__ . '/Services/VacationBreakService.php';
require_once __DIR__ . '/Services/MerchService.php';
require_once __DIR__ . '/Services/VacationProfileService.php';
require_once __DIR__ . '/Services/DreamService.php';
require_once __DIR__ . '/Services/WeatherEnvyService.php';
require_once __DIR__ . '/Services/RoastService.php';
require_once __DIR__ . '/Services/VacationAgentService.php';
require_once __DIR__ . '/Services/NotificationService.php';
require_once __DIR__ . '/Services/TravelMatchService.php';
require_once __DIR__ . '/Services/TravelMessageService.php';
require_once __DIR__ . '/Services/MatchEngagementService.php';

