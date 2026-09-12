<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile) || !is_readable($configFile)) {
    error_log('Vacation Brain config.php not found or not readable at: ' . $configFile);
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Vacation Brain configuration required</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f7f2e8;color:#123643;margin:0;padding:40px}.card{max-width:680px;margin:8vh auto;background:#fff;border:1px solid #e4ddd2;border-radius:18px;padding:30px;box-shadow:0 14px 38px rgba(20,50,60,.08)}h1{margin-top:0;font-size:28px}code{background:#f1ece3;padding:2px 6px;border-radius:6px}p{line-height:1.6}</style></head><body><div class="card"><h1>Vacation Brain configuration not found</h1><p>Vacation Brain reads one configuration file from the web root.</p><p>Make sure <code>config.php</code> is in the same directory as <code>index.php</code> and is readable by PHP.</p></div></body></html>';
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
require_once __DIR__ . '/Services/AccountTypeService.php';
require_once __DIR__ . '/Services/DestinationOwnerService.php';
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
require_once __DIR__ . '/Services/VacationPhotoAlbumService.php';
require_once __DIR__ . '/Services/VacationPhotoJobService.php';
require_once __DIR__ . '/Services/DashboardDestinationContextService.php';
require_once __DIR__ . '/Services/DreamService.php';
require_once __DIR__ . '/Services/TripMemoryService.php';
require_once __DIR__ . '/Services/TravelerMemoryGraphService.php';
require_once __DIR__ . '/Services/TravelProviderSettingsService.php';
require_once __DIR__ . '/Services/TravelDataProviderService.php';
require_once __DIR__ . '/Services/LiveTravelDataProviderService.php';
require_once __DIR__ . '/Services/TripIntelligenceService.php';
require_once __DIR__ . '/Services/TripLiveIntelligenceService.php';
require_once __DIR__ . '/Services/TripSupervisorService.php';
require_once __DIR__ . '/Services/TripAgentService.php';
require_once __DIR__ . '/Services/LiveTravelAgentContextService.php';
require_once __DIR__ . '/Services/BookingActionAgentContextService.php';
require_once __DIR__ . '/Services/TripCollaborationService.php';
require_once __DIR__ . '/Services/TripCollaborationAgentContextService.php';
require_once __DIR__ . '/Services/TripItineraryIntelligenceService.php';
require_once __DIR__ . '/Services/TripItineraryAgentContextService.php';
require_once __DIR__ . '/Services/TripTravelDayCopilotService.php';
require_once __DIR__ . '/Services/TripTravelDayCopilotAgentContextService.php';
require_once __DIR__ . '/Services/TripRecoveryIntelligenceService.php';
require_once __DIR__ . '/Services/TripRecoveryAgentContextService.php';
require_once __DIR__ . '/Services/TripDisruptionResolutionService.php';
require_once __DIR__ . '/Services/TripDisruptionResolutionAgentContextService.php';
require_once __DIR__ . '/Services/TripCostIntelligenceService.php';
require_once __DIR__ . '/Services/TripCostIntelligenceAgentContextService.php';
require_once __DIR__ . '/Services/TripAffordabilityService.php';
require_once __DIR__ . '/Services/TripAffordabilityAgentContextService.php';
require_once __DIR__ . '/Services/LocalConciergeService.php';
require_once __DIR__ . '/Services/WeatherEnvyService.php';
require_once __DIR__ . '/Services/RoastService.php';
require_once __DIR__ . '/Services/TripUnifiedInboxService.php';
require_once __DIR__ . '/Services/TripUnifiedInboxAgentContextService.php';
require_once __DIR__ . '/Services/TripUnifiedInboxWorkerService.php';
require_once __DIR__ . '/Services/VacationAgentService.php';
require_once __DIR__ . '/Services/NotificationService.php';
require_once __DIR__ . '/Services/TravelWatchService.php';
require_once __DIR__ . '/Services/TripAgentBatchService.php';
require_once __DIR__ . '/Services/TripAgentActionService.php';
require_once __DIR__ . '/Services/TripAgentJobService.php';
require_once __DIR__ . '/Services/TripAgentAutomationService.php';
require_once __DIR__ . '/Services/TripAgentExecutionService.php';
require_once __DIR__ . '/Services/AutonomousTravelOperationsService.php';
require_once __DIR__ . '/Services/TripBookingService.php';
require_once __DIR__ . '/Services/TripBookingImportService.php';
require_once __DIR__ . '/Services/BookingImportAgentContextService.php';
require_once __DIR__ . '/Services/BookingMailboxService.php';
require_once __DIR__ . '/Services/BookingMailboxAgentContextService.php';
require_once __DIR__ . '/Services/TripBookingActionService.php';
require_once __DIR__ . '/Services/TripFlightTrackingService.php';
require_once __DIR__ . '/Services/TripBookingReminderService.php';
require_once __DIR__ . '/Services/TripTravelOperationsService.php';
require_once __DIR__ . '/Services/TripCommandCenterService.php';
require_once __DIR__ . '/Services/ProactiveTravelService.php';
require_once __DIR__ . '/Services/VacationBrainActivityService.php';
require_once __DIR__ . '/Services/TravelMatchService.php';
require_once __DIR__ . '/Services/TravelMessageService.php';
require_once __DIR__ . '/Services/MatchEngagementService.php';