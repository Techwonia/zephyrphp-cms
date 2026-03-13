<?php

use ZephyrPHP\Router\Route;
use ZephyrPHP\Cms\Controllers\CmsController;
use ZephyrPHP\Cms\Controllers\CollectionController;
use ZephyrPHP\Cms\Controllers\EntryController;
use ZephyrPHP\Cms\Controllers\MediaController;
use ZephyrPHP\Cms\Controllers\DatabaseSettingsController;
use ZephyrPHP\Cms\Controllers\PageTypeController;
use ZephyrPHP\Cms\Controllers\PageController;
use ZephyrPHP\Cms\Controllers\PageFrontendController;
use ZephyrPHP\Cms\Controllers\ThemeController;
use ZephyrPHP\Cms\Controllers\ThemeCustomizerController;
use ZephyrPHP\Cms\Controllers\UserController;
use ZephyrPHP\Cms\Controllers\RoleController;
use ZephyrPHP\Cms\Controllers\ProfileController;
use ZephyrPHP\Cms\Controllers\SystemSettingsController;
use ZephyrPHP\Cms\Controllers\ApiKeyController;
use ZephyrPHP\Cms\Controllers\AssetSettingsController;
use ZephyrPHP\Cms\Controllers\AppController;
use ZephyrPHP\Cms\Api\ContentApiController;
use ZephyrPHP\Cms\Api\ApiV1Controller;
use ZephyrPHP\Cms\Api\OAuthController;
use ZephyrPHP\Cms\Api\MarketplaceApiController;
use ZephyrPHP\Cms\Controllers\OAuthClientController;
use ZephyrPHP\Cms\Controllers\MarketplaceController;
use ZephyrPHP\Cms\Controllers\SellerController;
use ZephyrPHP\Cms\Controllers\AiBuilderController;
use ZephyrPHP\Cms\Controllers\FormController;
use ZephyrPHP\Cms\Controllers\FormSubmissionController;
use ZephyrPHP\Cms\Controllers\FormPaymentController;

// CMS Admin Routes (protected by auth middleware)
Route::group(['prefix' => '/cms', 'middleware' => [\ZephyrPHP\Middleware\AuthMiddleware::class]], function () {
    // Dashboard
    Route::get('/', [CmsController::class, 'dashboard']);

    // Marketplace Apps
    Route::get('/apps', [AppController::class, 'index']);
    Route::post('/apps/install', [AppController::class, 'installUpload']);
    Route::post('/apps/{slug}/enable', [AppController::class, 'enable']);
    Route::post('/apps/{slug}/disable', [AppController::class, 'disable']);
    Route::post('/apps/{slug}/uninstall', [AppController::class, 'uninstallApp']);
    Route::post('/apps/{slug}/update', [AppController::class, 'updateApp']);

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

    // Entries (nested under collection)
    Route::get('/collections/{slug}/entries', [EntryController::class, 'index']);
    Route::get('/collections/{slug}/entries/create', [EntryController::class, 'create']);
    Route::post('/collections/{slug}/entries', [EntryController::class, 'store']);
    Route::get('/collections/{slug}/entries/{id}', [EntryController::class, 'edit']);
    Route::get('/collections/{slug}/entries/{id}/history', [EntryController::class, 'history']);
    Route::post('/collections/{slug}/entries/{id}/restore/{revisionId}', [EntryController::class, 'restore']);
    Route::post('/collections/{slug}/entries/{id}', [EntryController::class, 'update']);
    Route::post('/collections/{slug}/entries/{id}/delete', [EntryController::class, 'destroy']);

    // Media
    Route::get('/media', [MediaController::class, 'index']);
    Route::get('/media/browse', [MediaController::class, 'browse']);
    Route::post('/media/upload', [MediaController::class, 'upload']);
    Route::post('/media/{id}/delete', [MediaController::class, 'destroy']);

    // Page Types
    Route::get('/pages', [PageTypeController::class, 'index']);
    Route::get('/pages/types/create', [PageTypeController::class, 'create']);
    Route::post('/pages/types', [PageTypeController::class, 'store']);
    Route::get('/pages/types/{slug}', [PageTypeController::class, 'edit']);
    Route::post('/pages/types/{slug}', [PageTypeController::class, 'update']);
    Route::post('/pages/types/{slug}/delete', [PageTypeController::class, 'destroy']);
    Route::post('/pages/types/{slug}/fields', [PageTypeController::class, 'addField']);
    Route::post('/pages/types/{slug}/fields/{id}', [PageTypeController::class, 'updateField']);
    Route::post('/pages/types/{slug}/fields/{id}/delete', [PageTypeController::class, 'deleteField']);
    Route::post('/pages/types/{slug}/template', [PageTypeController::class, 'saveTemplate']);

    // Pages (entries under a page type)
    Route::get('/pages/{ptSlug}/list', [PageController::class, 'index']);
    Route::get('/pages/{ptSlug}/create', [PageController::class, 'create']);
    Route::post('/pages/{ptSlug}', [PageController::class, 'store']);
    Route::get('/pages/{ptSlug}/preview/{id}', [PageController::class, 'preview']);
    Route::get('/pages/{ptSlug}/{id}', [PageController::class, 'edit']);
    Route::post('/pages/{ptSlug}/{id}', [PageController::class, 'update']);
    Route::post('/pages/{ptSlug}/{id}/delete', [PageController::class, 'destroy']);

    // Theme Customizer
    Route::get('/themes/{slug}/customize', [ThemeCustomizerController::class, 'customize']);
    Route::get('/themes/{slug}/customize/preview', [ThemeCustomizerController::class, 'preview']);
    Route::post('/themes/{slug}/customize/save', [ThemeCustomizerController::class, 'save']);
    Route::get('/themes/{slug}/customize/sections', [ThemeCustomizerController::class, 'listSections']);
    Route::get('/themes/{slug}/customize/schema/{type}', [ThemeCustomizerController::class, 'sectionSchema']);
    Route::get('/themes/{slug}/customize/collections', [ThemeCustomizerController::class, 'listCollections']);
    Route::get('/themes/{slug}/customize/collection-fields/{collectionSlug}', [ThemeCustomizerController::class, 'collectionFields']);

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
    Route::post('/collections/{slug}/import', [\ZephyrPHP\Cms\Controllers\EntryController::class, 'import']);

    // Marketplace Browse
    Route::get('/marketplace', [MarketplaceController::class, 'index']);
    Route::get('/marketplace/{slug}', [MarketplaceController::class, 'show']);
    Route::post('/marketplace/{slug}/install', [MarketplaceController::class, 'install']);

    // Seller Portal
    Route::get('/seller', [SellerController::class, 'index']);
    Route::get('/seller/submit', [SellerController::class, 'create']);
    Route::post('/seller/submit', [SellerController::class, 'store']);

    // Form Builder
    Route::get('/forms', [FormController::class, 'index']);
    Route::get('/forms/create', [FormController::class, 'create']);
    Route::post('/forms', [FormController::class, 'store']);
    Route::get('/forms/templates', [FormController::class, 'templates']);
    Route::post('/forms/templates/{templateSlug}/create', [FormController::class, 'createFromTemplate']);
    Route::get('/forms/{slug}', [FormController::class, 'edit']);
    Route::post('/forms/{slug}', [FormController::class, 'update']);
    Route::post('/forms/{slug}/delete', [FormController::class, 'destroy']);
    Route::post('/forms/{slug}/duplicate', [FormController::class, 'duplicate']);

    // Form Fields (AJAX)
    Route::post('/forms/{slug}/fields', [FormController::class, 'addField']);
    Route::post('/forms/{slug}/fields/{id}', [FormController::class, 'updateField']);
    Route::post('/forms/{slug}/fields/{id}/delete', [FormController::class, 'deleteField']);
    Route::post('/forms/{slug}/fields/reorder', [FormController::class, 'reorderFields']);

    // Form Steps (AJAX)
    Route::post('/forms/{slug}/steps', [FormController::class, 'addStep']);
    Route::post('/forms/{slug}/steps/{id}', [FormController::class, 'updateStep']);
    Route::post('/forms/{slug}/steps/{id}/delete', [FormController::class, 'deleteStep']);

    // Form Submissions
    Route::get('/forms/{slug}/submissions', [FormController::class, 'submissions']);
    Route::get('/forms/{slug}/submissions/export', [FormController::class, 'exportSubmissions']);
    Route::get('/forms/{slug}/submissions/{id}', [FormController::class, 'viewSubmission']);
    Route::post('/forms/{slug}/submissions/{id}/delete', [FormController::class, 'deleteSubmission']);

    // AI Builder
    Route::get('/ai-builder', [AiBuilderController::class, 'index']);
    Route::post('/ai-builder/generate', [AiBuilderController::class, 'generatePage']);
    Route::post('/ai-builder/save-page', [AiBuilderController::class, 'savePage']);
    Route::post('/ai-builder/save-section', [AiBuilderController::class, 'saveSection']);
    Route::get('/ai-builder/settings', [AiBuilderController::class, 'settings']);
    Route::post('/ai-builder/settings', [AiBuilderController::class, 'updateSettings']);
});


// Public Form Routes (no auth)
Route::post('/forms/{slug}/submit', [FormSubmissionController::class, 'submit']);
Route::get('/forms/{slug}/success', [FormSubmissionController::class, 'success']);
Route::get('/forms/payment/callback/{gateway}', [FormPaymentController::class, 'callback']);
Route::post('/forms/webhooks/{gateway}', [FormPaymentController::class, 'webhook']);

// Sitemap
Route::get('/sitemap.xml', [\ZephyrPHP\Cms\Controllers\SitemapController::class, 'index']);

// Public Page Frontend
Route::get('/page/{slug}', [PageFrontendController::class, 'show']);

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
    // Pages
    Route::get('/pages', [ApiV1Controller::class, 'listPages']);
    Route::get('/pages/{id}', [ApiV1Controller::class, 'getPage']);

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
