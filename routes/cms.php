<?php

use ZephyrPHP\Router\Route;
use ZephyrPHP\Cms\Controllers\CmsController;
use ZephyrPHP\Cms\Controllers\CollectionController;
use ZephyrPHP\Cms\Controllers\EntryController;
use ZephyrPHP\Cms\Controllers\MediaController;
use ZephyrPHP\Cms\Controllers\DatabaseSettingsController;
use ZephyrPHP\Cms\Controllers\ThemeController;
use ZephyrPHP\Cms\Controllers\ThemeCustomizerController;
use ZephyrPHP\Cms\Controllers\UserController;
use ZephyrPHP\Cms\Controllers\RoleController;
use ZephyrPHP\Cms\Controllers\ProfileController;
use ZephyrPHP\Cms\Controllers\SystemSettingsController;
use ZephyrPHP\Cms\Controllers\ApiKeyController;
use ZephyrPHP\Cms\Controllers\AssetSettingsController;
use ZephyrPHP\Cms\Api\ContentApiController;
use ZephyrPHP\Cms\Api\ApiV1Controller;
use ZephyrPHP\Cms\Api\OAuthController;
use ZephyrPHP\Cms\Api\MarketplaceApiController;
use ZephyrPHP\Cms\Controllers\OAuthClientController;
use ZephyrPHP\Cms\Controllers\MarketplaceController;
use ZephyrPHP\Cms\Controllers\AiBuilderController;
use ZephyrPHP\Cms\Controllers\ActivityLogController;
use ZephyrPHP\Cms\Controllers\NotificationController;
use ZephyrPHP\Cms\Controllers\EmailTemplateController;
use ZephyrPHP\Cms\Controllers\LanguageController;
use ZephyrPHP\Cms\Controllers\SystemHealthController;
use ZephyrPHP\Cms\Controllers\LogViewerController;
use ZephyrPHP\Cms\Controllers\MaintenanceController;
use ZephyrPHP\Cms\Controllers\CacheController;
use ZephyrPHP\Cms\Controllers\MailSettingsController;
use ZephyrPHP\Cms\Controllers\WebhookController;
use ZephyrPHP\Cms\Controllers\RouteViewerController;
use ZephyrPHP\Cms\Controllers\ModuleManagerController;
use ZephyrPHP\Cms\Controllers\DatabaseToolsController;
use ZephyrPHP\Cms\Controllers\BackupController;
use ZephyrPHP\Cms\Controllers\FileManagerController;
use ZephyrPHP\Cms\Controllers\TranslationManagerController;
use ZephyrPHP\Cms\Controllers\ScheduledTaskController;
use ZephyrPHP\Cms\Controllers\AuthSettingsController;
use ZephyrPHP\Cms\Controllers\SessionManagerController;
use ZephyrPHP\Cms\Controllers\ApiSettingsController;
use ZephyrPHP\Cms\Controllers\CacheSettingsController;
use ZephyrPHP\Cms\Controllers\PermissionBuilderController;
use ZephyrPHP\Cms\Controllers\ErrorPageController;
use ZephyrPHP\Cms\Controllers\WorkflowVisualizerController;
use ZephyrPHP\Cms\Controllers\ApiAnalyticsController;
use ZephyrPHP\Cms\Controllers\QueueMonitorController;
use ZephyrPHP\Cms\Controllers\SystemMonitorController;
use ZephyrPHP\Cms\Controllers\ThemeAssetController;
use ZephyrPHP\Cms\Controllers\ThemeCodeEditorController;

// CMS Admin Routes (protected by auth middleware)
Route::group(['prefix' => '/cms', 'middleware' => [\ZephyrPHP\Middleware\AuthMiddleware::class]], function () {
    // Dashboard
    Route::get('/', [CmsController::class, 'dashboard']);
    Route::post('/dashboard/layout', [CmsController::class, 'saveLayout']);
    Route::post('/publish-scheduled', [CmsController::class, 'publishScheduled']);

    // OAuth Client Management
    Route::get('/oauth-clients', [OAuthClientController::class, 'index']);
    Route::post('/oauth-clients', [OAuthClientController::class, 'store']);
    Route::post('/oauth-clients/{id}/delete', [OAuthClientController::class, 'destroy']);

    // Collections
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::get('/collections/create', [CollectionController::class, 'create']);
    Route::post('/collections', [CollectionController::class, 'store']);
    Route::get('/collections/{slug}', [CollectionController::class, 'edit']);
    Route::post('/collections/{slug}', [CollectionController::class, 'update']);
    Route::post('/collections/{slug}/delete', [CollectionController::class, 'destroy']);

    // Collection fields API (JSON)
    Route::get('/collections/{slug}/fields-json', [CollectionController::class, 'fieldsJson']);

    // Fields (nested under collection)
    Route::post('/collections/{slug}/fields', [CollectionController::class, 'addField']);
    Route::post('/collections/{slug}/fields/{id}', [CollectionController::class, 'updateField']);
    Route::post('/collections/{slug}/fields/{id}/delete', [CollectionController::class, 'deleteField']);

    // Saved Views (per-collection filter presets)
    Route::post('/collections/{slug}/views', [EntryController::class, 'saveView']);
    Route::post('/collections/{slug}/views/{viewId}/delete', [EntryController::class, 'deleteView']);

    // Entries (nested under collection)
    Route::get('/collections/{slug}/entries', [EntryController::class, 'index']);
    Route::get('/collections/{slug}/entries/create', [EntryController::class, 'create']);
    Route::post('/collections/{slug}/entries', [EntryController::class, 'store']);
    Route::get('/collections/{slug}/entries/{id}', [EntryController::class, 'edit']);
    Route::get('/collections/{slug}/entries/{id}/history', [EntryController::class, 'history']);
    Route::get('/collections/{slug}/entries/{id}/seo-score', [EntryController::class, 'seoAnalysis']);
    Route::get('/collections/{slug}/entries/{id}/translate/{locale}', [EntryController::class, 'translate']);
    Route::post('/collections/{slug}/entries/{id}/translate/{locale}', [EntryController::class, 'saveTranslation']);
    Route::post('/collections/{slug}/entries/{id}/restore/{revisionId}', [EntryController::class, 'restore']);
    Route::post('/collections/{slug}/entries/{id}/workflow/advance', [EntryController::class, 'advanceWorkflow']);
    Route::post('/collections/{slug}/entries/{id}/workflow/reject', [EntryController::class, 'rejectWorkflow']);
    Route::post('/collections/{slug}/entries/{id}', [EntryController::class, 'update']);
    Route::post('/collections/{slug}/entries/{id}/delete', [EntryController::class, 'destroy']);

    // Languages
    Route::get('/languages', [LanguageController::class, 'index']);
    Route::post('/languages', [LanguageController::class, 'store']);
    Route::post('/languages/{id}', [LanguageController::class, 'update']);
    Route::post('/languages/{id}/delete', [LanguageController::class, 'destroy']);

    // Media
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/browse', [MediaController::class, 'browse']);
    Route::get('/media/{id}', [MediaController::class, 'detail']);
    Route::post('/media/upload', [MediaController::class, 'upload']);
    Route::post('/media/bulk', [MediaController::class, 'bulk']);
    Route::post('/media/folders/create', [MediaController::class, 'createFolder']);
    Route::post('/media/folders/rename', [MediaController::class, 'renameFolder']);
    Route::post('/media/folders/delete', [MediaController::class, 'deleteFolder']);
    Route::post('/media/{id}/update', [MediaController::class, 'update']);
    Route::post('/media/{id}/delete', [MediaController::class, 'destroy']);
    Route::get('/media/{id}/usage', [MediaController::class, 'usage']);

    // Theme Customizer
    Route::get('/themes/{slug}/customize', [ThemeCustomizerController::class, 'customize']);
    Route::get('/themes/{slug}/customize/preview', [ThemeCustomizerController::class, 'preview']);
    Route::post('/themes/{slug}/customize/preview', [ThemeCustomizerController::class, 'previewPost']);
    Route::post('/themes/{slug}/customize/save', [ThemeCustomizerController::class, 'save']);
    Route::get('/themes/{slug}/customize/sections', [ThemeCustomizerController::class, 'listSections']);
    Route::get('/themes/{slug}/customize/schema/{type}', [ThemeCustomizerController::class, 'sectionSchema']);
    Route::get('/themes/{slug}/customize/collections', [ThemeCustomizerController::class, 'listCollections']);
    Route::get('/themes/{slug}/customize/collection-fields/{collectionSlug}', [ThemeCustomizerController::class, 'collectionFields']);

    // Theme Assets (per-theme CSS, JS, fonts)
    Route::get('/themes/{slug}/assets', [ThemeAssetController::class, 'list']);
    Route::post('/themes/{slug}/assets/upload', [ThemeAssetController::class, 'upload']);
    Route::post('/themes/{slug}/assets/delete', [ThemeAssetController::class, 'delete']);

    // Theme Code Editor
    Route::get('/themes/{slug}/code', [ThemeCodeEditorController::class, 'index']);
    Route::get('/themes/{slug}/code/files', [ThemeCodeEditorController::class, 'listFiles']);
    Route::get('/themes/{slug}/code/file', [ThemeCodeEditorController::class, 'readFile']);
    Route::post('/themes/{slug}/code/file', [ThemeCodeEditorController::class, 'saveFile']);
    Route::post('/themes/{slug}/code/file/create', [ThemeCodeEditorController::class, 'createFile']);
    Route::post('/themes/{slug}/code/file/delete', [ThemeCodeEditorController::class, 'deleteFile']);
    Route::post('/themes/{slug}/code/folder', [ThemeCodeEditorController::class, 'createFolder']);
    Route::post('/themes/{slug}/code/file/rename', [ThemeCodeEditorController::class, 'renameFile']);

    // Theme section creation
    Route::post('/themes/{slug}/sections/create', [ThemeController::class, 'createSection']);

    // Theme Install/Uninstall (before slug routes to avoid collision)
    Route::post('/themes/install', [ThemeController::class, 'installUpload']);
    Route::post('/themes/{slug}/uninstall', [ThemeController::class, 'uninstallTheme']);

    // Themes
    Route::get('/themes', [ThemeController::class, 'index']);
    Route::get('/themes/create', [ThemeController::class, 'create']);
    Route::post('/themes', [ThemeController::class, 'store']);
    Route::get('/themes/{slug}', [ThemeController::class, 'edit']);
    Route::post('/themes/{slug}', [ThemeController::class, 'update']);
    Route::post('/themes/{slug}/publish', [ThemeController::class, 'publish']);
    Route::post('/themes/{slug}/delete', [ThemeController::class, 'destroy']);
    Route::get('/themes/{slug}/preview', [ThemeController::class, 'preview']);
    Route::post('/themes/{slug}/file', [ThemeController::class, 'saveFile']);
    Route::post('/themes/{slug}/pages/add', [ThemeController::class, 'addPage']);
    Route::post('/themes/{slug}/pages/update', [ThemeController::class, 'updatePage']);
    Route::post('/themes/{slug}/pages/delete', [ThemeController::class, 'removePage']);


    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/create', [UserController::class, 'create']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'edit']);
    Route::post('/users/{id}', [UserController::class, 'update']);
    Route::post('/users/{id}/delete', [UserController::class, 'destroy']);

    // Roles
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/create', [RoleController::class, 'create']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}', [RoleController::class, 'edit']);
    Route::post('/roles/{id}', [RoleController::class, 'update']);
    Route::post('/roles/{id}/delete', [RoleController::class, 'destroy']);

    // Permission Builder
    Route::get('/permissions', [PermissionBuilderController::class, 'index']);
    Route::post('/permissions/save-matrix', [PermissionBuilderController::class, 'saveMatrix']);
    Route::post('/permissions/save-collection', [PermissionBuilderController::class, 'saveCollectionPermissions']);
    Route::post('/permissions/custom/add', [PermissionBuilderController::class, 'addCustomPermission']);
    Route::post('/permissions/custom/{id}/delete', [PermissionBuilderController::class, 'deleteCustomPermission']);

    // Profile Settings
    Route::get('/settings/profile', [ProfileController::class, 'index']);
    Route::post('/settings/profile', [ProfileController::class, 'update']);

    // System Settings
    Route::get('/settings/system', [SystemSettingsController::class, 'index']);
    Route::post('/settings/system', [SystemSettingsController::class, 'update']);

    // Asset Configuration (config/assets.php + public/assets/)
    Route::get('/settings/assets', [AssetSettingsController::class, 'index']);
    Route::post('/settings/assets', [AssetSettingsController::class, 'update']);
    Route::post('/settings/assets/upload', [AssetSettingsController::class, 'upload']);
    Route::post('/settings/assets/delete', [AssetSettingsController::class, 'deleteFile']);
    Route::get('/settings/assets/files', [AssetSettingsController::class, 'listFiles']);
    Route::post('/settings/assets/minify', [AssetSettingsController::class, 'minify']);

    // Database Settings
    Route::get('/settings/database', [DatabaseSettingsController::class, 'index']);
    Route::post('/settings/database', [DatabaseSettingsController::class, 'update']);
    Route::post('/settings/database/test', [DatabaseSettingsController::class, 'test']);
    Route::post('/settings/database/list', [DatabaseSettingsController::class, 'listDatabases']);

    // API Keys
    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::get('/api-keys/create', [ApiKeyController::class, 'create']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::post('/api-keys/{id}/toggle', [ApiKeyController::class, 'toggleStatus']);
    Route::post('/api-keys/{id}/delete', [ApiKeyController::class, 'destroy']);

    // Bulk Operations
    Route::post('/collections/{slug}/entries/bulk', [\ZephyrPHP\Cms\Controllers\EntryController::class, 'bulk']);

    // Import/Export
    Route::get('/collections/{slug}/export', [\ZephyrPHP\Cms\Controllers\EntryController::class, 'export']);
    Route::post('/collections/{slug}/import/preview', [\ZephyrPHP\Cms\Controllers\EntryController::class, 'importPreview']);
    Route::post('/collections/{slug}/import', [\ZephyrPHP\Cms\Controllers\EntryController::class, 'import']);

    // Marketplace Browse
    Route::get('/marketplace', [MarketplaceController::class, 'index']);
    Route::get('/marketplace/{slug}', [MarketplaceController::class, 'show']);
    Route::post('/marketplace/{slug}/install', [MarketplaceController::class, 'install']);

    // AI Builder
    Route::get('/ai-builder', [AiBuilderController::class, 'index']);
    Route::post('/ai-builder/generate', [AiBuilderController::class, 'generatePage']);
    Route::post('/ai-builder/save-page', [AiBuilderController::class, 'savePage']);
    Route::post('/ai-builder/save-section', [AiBuilderController::class, 'saveSection']);
    Route::get('/ai-builder/settings', [AiBuilderController::class, 'settings']);
    Route::post('/ai-builder/settings', [AiBuilderController::class, 'updateSettings']);

    // Activity Log
    Route::get('/activity-log', [ActivityLogController::class, 'index']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::post('/notifications/preferences', [NotificationController::class, 'savePreferences']);

    // Email Templates
    Route::get('/email-templates', [EmailTemplateController::class, 'index']);
    Route::get('/email-templates/{id}', [EmailTemplateController::class, 'edit']);
    Route::post('/email-templates/{id}', [EmailTemplateController::class, 'update']);
    Route::get('/email-templates/{id}/preview', [EmailTemplateController::class, 'preview']);
    Route::post('/email-templates/{id}/test', [EmailTemplateController::class, 'testSend']);

    // System Health
    Route::get('/system/health', [SystemHealthController::class, 'index']);

    // Log Viewer
    Route::get('/system/logs', [LogViewerController::class, 'index']);
    Route::get('/system/logs/view', [LogViewerController::class, 'view']);
    Route::post('/system/logs/clear', [LogViewerController::class, 'clear']);
    Route::post('/system/logs/clear-all', [LogViewerController::class, 'clearAll']);
    Route::get('/system/logs/download', [LogViewerController::class, 'downloadLog']);

    // Maintenance Mode
    Route::get('/system/maintenance', [MaintenanceController::class, 'index']);
    Route::post('/system/maintenance/activate', [MaintenanceController::class, 'activate']);
    Route::post('/system/maintenance/deactivate', [MaintenanceController::class, 'deactivate']);

    // Cache Management
    Route::get('/system/cache', [CacheController::class, 'index']);
    Route::post('/system/cache/clear', [CacheController::class, 'clear']);
    Route::post('/system/cache/clear-all', [CacheController::class, 'clearAll']);
    Route::post('/system/cache/config', [CacheController::class, 'cacheConfig']);

    // Mail Settings
    Route::get('/settings/mail', [MailSettingsController::class, 'index']);
    Route::post('/settings/mail', [MailSettingsController::class, 'update']);
    Route::post('/settings/mail/test', [MailSettingsController::class, 'testSend']);

    // Webhooks
    Route::get('/webhooks', [WebhookController::class, 'index']);
    Route::post('/webhooks', [WebhookController::class, 'store']);
    Route::post('/webhooks/{id}/toggle', [WebhookController::class, 'toggle']);
    Route::post('/webhooks/{id}/test', [WebhookController::class, 'test']);
    Route::post('/webhooks/{id}/delete', [WebhookController::class, 'destroy']);

    // Route Viewer
    Route::get('/system/routes', [RouteViewerController::class, 'index']);

    // Module Manager
    Route::get('/system/modules', [ModuleManagerController::class, 'index']);
    Route::post('/system/modules/{name}/toggle', [ModuleManagerController::class, 'toggle']);

    // Database Tools
    Route::get('/system/database', [DatabaseToolsController::class, 'index']);
    Route::get('/system/database/browse/{table}', [DatabaseToolsController::class, 'browse']);
    Route::get('/system/database/download', [DatabaseToolsController::class, 'downloadBackup']);

    // Backups
    Route::get('/system/backups', [BackupController::class, 'index']);
    Route::post('/system/backups/create', [BackupController::class, 'create']);
    Route::get('/system/backups/{filename}/download', [BackupController::class, 'downloadBackup']);
    Route::post('/system/backups/{filename}/delete', [BackupController::class, 'destroy']);

    // File Manager
    Route::get('/system/files', [FileManagerController::class, 'index']);
    Route::get('/system/files/edit', [FileManagerController::class, 'edit']);
    Route::post('/system/files/save', [FileManagerController::class, 'save']);

    // Translation Manager
    Route::get('/system/translations', [TranslationManagerController::class, 'index']);
    Route::post('/system/translations/update', [TranslationManagerController::class, 'update']);
    Route::post('/system/translations/add-key', [TranslationManagerController::class, 'addKey']);
    Route::post('/system/translations/create-group', [TranslationManagerController::class, 'createGroup']);

    // Scheduled Tasks
    Route::get('/system/scheduled-tasks', [ScheduledTaskController::class, 'index']);
    Route::post('/system/scheduled-tasks', [ScheduledTaskController::class, 'store']);
    Route::post('/system/scheduled-tasks/{id}/run', [ScheduledTaskController::class, 'run']);
    Route::post('/system/scheduled-tasks/{id}/toggle', [ScheduledTaskController::class, 'toggle']);
    Route::post('/system/scheduled-tasks/{id}/delete', [ScheduledTaskController::class, 'destroy']);

    // Translation Progress
    Route::get('/system/translations/progress', [TranslationManagerController::class, 'progress']);

    // Auth Settings
    Route::get('/settings/auth', [AuthSettingsController::class, 'index']);
    Route::post('/settings/auth', [AuthSettingsController::class, 'update']);
    Route::post('/settings/auth/oauth', [AuthSettingsController::class, 'updateOAuth']);

    // Session Manager
    Route::get('/system/sessions', [SessionManagerController::class, 'index']);
    Route::post('/system/sessions/{id}/terminate', [SessionManagerController::class, 'terminate']);
    Route::post('/system/sessions/terminate-all', [SessionManagerController::class, 'terminateAll']);
    Route::post('/system/sessions/terminate-user/{userId}', [SessionManagerController::class, 'terminateUser']);

    // API Settings
    Route::get('/settings/api', [ApiSettingsController::class, 'index']);
    Route::post('/settings/api', [ApiSettingsController::class, 'update']);

    // Cache Settings
    Route::get('/settings/cache', [CacheSettingsController::class, 'index']);
    Route::post('/settings/cache', [CacheSettingsController::class, 'update']);
    Route::post('/settings/cache/test-redis', [CacheSettingsController::class, 'testRedis']);

    // Webhook Delivery Logs
    Route::get('/webhooks/{id}/logs', [WebhookController::class, 'logs']);
    Route::post('/webhooks/{id}/retry/{deliveryId}', [WebhookController::class, 'retry']);

    // Revision Diff
    Route::get('/collections/{slug}/entries/{id}/diff', [EntryController::class, 'diff']);

    // Error Pages & Messages
    Route::get('/settings/error-pages', [ErrorPageController::class, 'index']);
    Route::post('/settings/error-pages/http', [ErrorPageController::class, 'updateHttp']);
    Route::post('/settings/error-pages/database', [ErrorPageController::class, 'updateDatabase']);
    Route::post('/settings/error-pages/fields', [ErrorPageController::class, 'updateFields']);
    Route::post('/settings/error-pages/fields/add', [ErrorPageController::class, 'addField']);
    Route::get('/settings/error-pages/preview/{code}', [ErrorPageController::class, 'preview']);

    // Workflow Visualizer
    Route::get('/system/workflow', [WorkflowVisualizerController::class, 'index']);

    // API Analytics
    Route::get('/system/api-analytics', [ApiAnalyticsController::class, 'index']);

    // Queue Monitor
    Route::get('/system/queue', [QueueMonitorController::class, 'index']);
    Route::post('/system/queue/{id}/retry', [QueueMonitorController::class, 'retry']);
    Route::post('/system/queue/retry-all', [QueueMonitorController::class, 'retryAll']);
    Route::post('/system/queue/{id}/delete', [QueueMonitorController::class, 'delete']);
    Route::post('/system/queue/purge', [QueueMonitorController::class, 'purge']);

    // System Monitor
    Route::get('/system/monitor', [SystemMonitorController::class, 'index']);
    Route::get('/system/monitor/stats', [SystemMonitorController::class, 'stats']);
});


// CMS Module Assets
Route::get('/cms-assets/css/{file}', function (string $file) {
    $file = basename($file);
    $filePath = dirname(__DIR__) . '/assets/css/' . $file;
    if (!file_exists($filePath)) { http_response_code(404); return ''; }
    header('Content-Type: text/css');
    header('Cache-Control: public, max-age=86400');
    readfile($filePath);
    exit;
});
Route::get('/cms-assets/js/{file}', function (string $file) {
    $file = basename($file);
    $filePath = dirname(__DIR__) . '/assets/js/' . $file;
    if (!file_exists($filePath)) { http_response_code(404); return ''; }
    header('Content-Type: application/javascript');
    header('Cache-Control: public, max-age=86400');
    readfile($filePath);
    exit;
});

// Public Collection Submit (no auth required)
Route::post('/collections/{slug}/submit', [\ZephyrPHP\Cms\Controllers\PublicSubmitController::class, 'submit']);

// Sitemap
Route::get('/sitemap.xml', [\ZephyrPHP\Cms\Controllers\SitemapController::class, 'index']);


// CMS Public API Routes (legacy, API-key based)
Route::group(['prefix' => '/api/cms'], function () {
    Route::get('/{slug}', [ContentApiController::class, 'index']);
    Route::get('/{slug}/{id}', [ContentApiController::class, 'show']);
    Route::post('/{slug}', [ContentApiController::class, 'store']);
    Route::post('/{slug}/{id}', [ContentApiController::class, 'update']);
    Route::post('/{slug}/{id}/delete', [ContentApiController::class, 'destroy']);
});

// Marketplace Public API (server-side)
Route::group(['prefix' => '/marketplace/api/v1'], function () {
    Route::get('/items', [MarketplaceApiController::class, 'index']);
    Route::get('/items/{slug}', [MarketplaceApiController::class, 'show']);
    Route::get('/items/{slug}/download', [MarketplaceApiController::class, 'downloadItem']);
    Route::get('/items/{slug}/reviews', [MarketplaceApiController::class, 'reviews']);
    Route::post('/items/{slug}/reviews', [MarketplaceApiController::class, 'submitReview']);
    Route::post('/check-updates', [MarketplaceApiController::class, 'checkUpdates']);
});

// OAuth 2.0 Endpoints
Route::get('/oauth/authorize', [OAuthController::class, 'authorize']);
Route::post('/oauth/authorize', [OAuthController::class, 'authorizeApprove']);
Route::post('/oauth/token', [OAuthController::class, 'token']);
Route::post('/oauth/revoke', [OAuthController::class, 'revoke']);

// REST API v1 (OAuth-protected)
Route::group(['prefix' => '/api/v1', 'middleware' => [\ZephyrPHP\OAuth\OAuthMiddleware::class]], function () {
    // Collections + Entries
    Route::get('/collections', [ApiV1Controller::class, 'listCollections']);
    Route::get('/collections/{slug}/entries', [ApiV1Controller::class, 'listEntries']);
    Route::post('/collections/{slug}/entries', [ApiV1Controller::class, 'createEntry']);
    Route::put('/collections/{slug}/entries/{id}', [ApiV1Controller::class, 'updateEntry']);
    Route::delete('/collections/{slug}/entries/{id}', [ApiV1Controller::class, 'deleteEntry']);

    // Themes
    Route::get('/themes', [ApiV1Controller::class, 'listThemes']);

    // Users
    Route::get('/users', [ApiV1Controller::class, 'listUsers']);

    // Webhooks (self-service)
    Route::get('/webhooks', [ApiV1Controller::class, 'listWebhooks']);
    Route::post('/webhooks', [ApiV1Controller::class, 'createWebhook']);
    Route::delete('/webhooks/{id}', [ApiV1Controller::class, 'deleteWebhook']);
});
