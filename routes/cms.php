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
use ZephyrPHP\Cms\Controllers\ActivityLogController;
use ZephyrPHP\Cms\Controllers\NotificationController;
use ZephyrPHP\Cms\Controllers\LanguageController;
use ZephyrPHP\Cms\Controllers\SystemHealthController;
use ZephyrPHP\Cms\Controllers\LogViewerController;
use ZephyrPHP\Cms\Controllers\MaintenanceController;
use ZephyrPHP\Cms\Controllers\CacheController;
use ZephyrPHP\Cms\Controllers\MailSettingsController;
use ZephyrPHP\Cms\Controllers\WebhookController;
use ZephyrPHP\Cms\Controllers\BackupController;
use ZephyrPHP\Cms\Controllers\TranslationManagerController;
use ZephyrPHP\Cms\Controllers\AuthSettingsController;
use ZephyrPHP\Cms\Controllers\SessionManagerController;
use ZephyrPHP\Cms\Controllers\ApiSettingsController;
use ZephyrPHP\Cms\Controllers\CacheSettingsController;
use ZephyrPHP\Cms\Controllers\PermissionBuilderController;
use ZephyrPHP\Cms\Controllers\ErrorPageController;
use ZephyrPHP\Cms\Controllers\WorkflowVisualizerController;
use ZephyrPHP\Cms\Controllers\ThemeAssetController;
use ZephyrPHP\Cms\Controllers\ThemeCodeEditorController;
use ZephyrPHP\Cms\Controllers\PluginController;
use ZephyrPHP\Cms\Controllers\SearchController;
use ZephyrPHP\Cms\Controllers\RedirectController;

// CMS Admin Routes (protected by auth middleware, with auto scheduled publishing)
Route::group(['prefix' => '/' . admin_path(), 'middleware' => [\ZephyrPHP\Middleware\AuthMiddleware::class, \ZephyrPHP\Cms\Middleware\ScheduledPublishMiddleware::class]], function () {
    // Dashboard
    Route::get('/', [CmsController::class, 'dashboard']);
    Route::post('/dashboard/layout', [CmsController::class, 'saveLayout']);
    Route::post('/publish-scheduled', [CmsController::class, 'publishScheduled']);

    // Collections
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::get('/collections/create', [CollectionController::class, 'create']);
    Route::post('/collections', [CollectionController::class, 'store']);
    Route::get('/collections/{slug}', [CollectionController::class, 'edit']);
    Route::post('/collections/{slug}', [CollectionController::class, 'update']);
    Route::post('/collections/{slug}/delete', [CollectionController::class, 'destroy']);

    // Collection fields API (JSON)
    Route::get('/collections/{slug}/fields/{id}/json', [CollectionController::class, 'fieldJson']);
    Route::post('/collections/{slug}/fields/reorder', [CollectionController::class, 'reorderFields']);
    Route::get('/collections/{slug}/fields-json', [CollectionController::class, 'fieldsJson']);

    // Fields (nested under collection)
    Route::post('/collections/{slug}/fields', [CollectionController::class, 'addField']);
    Route::post('/collections/{slug}/fields/{id}', [CollectionController::class, 'updateField']);
    Route::post('/collections/{slug}/fields/{id}/delete', [CollectionController::class, 'deleteField']);

    // Saved Views (per-collection filter presets)
    Route::post('/collections/{slug}/views', [EntryController::class, 'saveView']);
    Route::post('/collections/{slug}/views/{viewId}/delete', [EntryController::class, 'deleteView']);

    // Content Templates (reusable entry templates)
    Route::post('/collections/{slug}/entries/{id}/save-template', [EntryController::class, 'saveAsTemplate']);
    Route::post('/collections/{slug}/templates/{templateId}/delete', [EntryController::class, 'deleteTemplate']);
    Route::get('/collections/{slug}/templates/{templateId}', [EntryController::class, 'getTemplateData']);

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
    Route::post('/collections/{slug}/entries/{id}/heartbeat', [EntryController::class, 'heartbeat']);
    Route::post('/collections/{slug}/entries/{id}/unlock', [EntryController::class, 'unlock']);
    // GET fallbacks for POST-only AJAX routes (browser lands here after session expiry redirect)
    Route::get('/collections/{slug}/entries/{id}/heartbeat', function(string $slug, string $id) { header('Location: ' . admin_url("collections/{$slug}/entries")); exit; });
    Route::get('/collections/{slug}/entries/{id}/unlock', function(string $slug, string $id) { header('Location: ' . admin_url("collections/{$slug}/entries")); exit; });
    Route::post('/collections/{slug}/entries/{id}/duplicate', [EntryController::class, 'duplicate']);
    Route::post('/collections/{slug}/entries/{id}/preview', [EntryController::class, 'preview']);
    Route::post('/collections/{slug}/entries/{id}', [EntryController::class, 'update']);
    Route::post('/collections/{slug}/entries/{id}/delete', [EntryController::class, 'destroy']);

    // Trash / Soft Deletes
    Route::get('/collections/{slug}/trash', [EntryController::class, 'trash']);
    Route::post('/collections/{slug}/entries/{id}/restore', [EntryController::class, 'restoreEntry']);
    Route::post('/collections/{slug}/entries/{id}/force-delete', [EntryController::class, 'forceDelete']);
    Route::post('/collections/{slug}/trash/empty', [EntryController::class, 'emptyTrash']);


    // Languages
    Route::get('/languages', [LanguageController::class, 'index']);
    Route::post('/languages', [LanguageController::class, 'store']);
    Route::post('/languages/{id}', [LanguageController::class, 'update']);
    Route::post('/languages/{id}/delete', [LanguageController::class, 'destroy']);

    // Media
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/browse', [MediaController::class, 'browse']);
    Route::get('/media/tags', [MediaController::class, 'tags']);
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
    Route::post('/themes/{slug}/code/upload', [ThemeCodeEditorController::class, 'uploadAsset']);

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
    Route::post('/users/invite', [UserController::class, 'invite']);
    Route::post('/users/invite/{id}/resend', [UserController::class, 'resendInvite']);
    Route::post('/users/invite/{id}/cancel', [UserController::class, 'cancelInvite']);
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

    // Plugins
    Route::get('/plugins', [PluginController::class, 'index']);
    Route::get('/plugins/browse', [PluginController::class, 'browse']);
    Route::post('/plugins/install', [PluginController::class, 'install']);
    Route::get('/plugins/{slug}/settings', [PluginController::class, 'settings']);
    Route::post('/plugins/{slug}/settings', [PluginController::class, 'saveSettings']);
    Route::post('/plugins/{slug}/uninstall', [PluginController::class, 'uninstall']);
    Route::post('/plugins/{slug}/toggle', [PluginController::class, 'toggle']);

    // Activity Log
    Route::get('/activity-log', [ActivityLogController::class, 'index']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::post('/notifications/preferences', [NotificationController::class, 'savePreferences']);

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

    // Backups
    Route::get('/system/backups', [BackupController::class, 'index']);
    Route::post('/system/backups/create', [BackupController::class, 'create']);
    Route::get('/system/backups/{filename}/download', [BackupController::class, 'downloadBackup']);
    Route::post('/system/backups/{filename}/delete', [BackupController::class, 'destroy']);

    // Translation Manager
    Route::get('/system/translations', [TranslationManagerController::class, 'index']);
    Route::post('/system/translations/update', [TranslationManagerController::class, 'update']);
    Route::post('/system/translations/add-key', [TranslationManagerController::class, 'addKey']);
    Route::post('/system/translations/create-group', [TranslationManagerController::class, 'createGroup']);
    Route::post('/system/translations/create-locale', [TranslationManagerController::class, 'createLocale']);
    Route::get('/system/translations/export', [TranslationManagerController::class, 'export']);
    Route::post('/system/translations/import', [TranslationManagerController::class, 'import']);
    Route::post('/system/translations/delete-key', [TranslationManagerController::class, 'deleteKey']);

    // Translation Progress
    Route::get('/system/translations/progress', [TranslationManagerController::class, 'progress']);

    // Auth Settings
    Route::get('/settings/auth', [AuthSettingsController::class, 'index']);
    Route::post('/settings/auth', [AuthSettingsController::class, 'update']);

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

    // Workflow Builder
    Route::get('/system/workflow', [WorkflowVisualizerController::class, 'index']);
    Route::post('/system/workflow/enable', [WorkflowVisualizerController::class, 'enable']);
    Route::post('/system/workflow/disable', [WorkflowVisualizerController::class, 'disable']);
    Route::post('/system/workflow/save-stages', [WorkflowVisualizerController::class, 'saveStages']);
    Route::post('/system/workflow/save-reviewers', [WorkflowVisualizerController::class, 'saveReviewers']);

    // Global Search
    Route::get('/search', [SearchController::class, 'search']);

    // Redirects
    Route::get('/redirects', [RedirectController::class, 'index']);
    Route::post('/redirects', [RedirectController::class, 'store']);
    Route::post('/redirects/{id}', [RedirectController::class, 'update']);
    Route::post('/redirects/{id}/delete', [RedirectController::class, 'destroy']);
    Route::post('/redirects/{id}/toggle', [RedirectController::class, 'toggleStatus']);

});


// CMS Module Assets (serves from cms/assets/ with subdirectory support)
$cmsAssetHandler = function (string ...$parts) {
    $path = implode('/', $parts);
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '..') || !preg_match('#^[a-zA-Z0-9/_.-]+$#', $path)) {
        http_response_code(400);
        return '';
    }

    $basePath = dirname(__DIR__) . '/assets/';
    $filePath = realpath($basePath . $path);
    if (!$filePath || !str_starts_with($filePath, realpath($basePath))) {
        http_response_code(404);
        return '';
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css' => 'text/css', 'js' => 'application/javascript',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif',
    ];
    $mime = $mimeTypes[$ext] ?? null;
    if (!$mime) { http_response_code(403); return ''; }

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800, immutable');
    readfile($filePath);
    exit;
};

// Flat: /cms-assets/css/file.css, /cms-assets/js/file.js, /cms-assets/fonts/inter/file.woff2
Route::get('/cms-assets/css/{file}', fn(string $file) => $cmsAssetHandler('css', $file));
Route::get('/cms-assets/js/{file}', fn(string $file) => $cmsAssetHandler('js', $file));
// Subdirectories: codemirror modes/themes, font variants
Route::get('/cms-assets/css/{dir}/{file}', fn(string $dir, string $file) => $cmsAssetHandler('css', $dir, $file));
Route::get('/cms-assets/js/{dir}/{file}', fn(string $dir, string $file) => $cmsAssetHandler('js', $dir, $file));
Route::get('/cms-assets/fonts/{dir}/{file}', fn(string $dir, string $file) => $cmsAssetHandler('fonts', $dir, $file));

// Public Collection Submit (no auth required)
Route::post('/collections/{slug}/submit', [\ZephyrPHP\Cms\Controllers\PublicSubmitController::class, 'submit']);

// Sitemap
Route::get('/sitemap.xml', [\ZephyrPHP\Cms\Controllers\SitemapController::class, 'index']);


