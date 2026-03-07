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
use ZephyrPHP\Cms\Api\ContentApiController;

// CMS Admin Routes (protected by auth middleware)
Route::group(['prefix' => '/cms', 'middleware' => [\ZephyrPHP\Middleware\AuthMiddleware::class]], function () {
    // Dashboard
    Route::get('/', [CmsController::class, 'dashboard']);

    // Collections
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::get('/collections/create', [CollectionController::class, 'create']);
    Route::post('/collections', [CollectionController::class, 'store']);
    Route::get('/collections/{slug}', [CollectionController::class, 'edit']);
    Route::post('/collections/{slug}', [CollectionController::class, 'update']);
    Route::post('/collections/{slug}/delete', [CollectionController::class, 'destroy']);

    // Fields (nested under collection)
    Route::post('/collections/{slug}/fields', [CollectionController::class, 'addField']);
    Route::post('/collections/{slug}/fields/{id}', [CollectionController::class, 'updateField']);
    Route::post('/collections/{slug}/fields/{id}/delete', [CollectionController::class, 'deleteField']);

    // Entries (nested under collection)
    Route::get('/collections/{slug}/entries', [EntryController::class, 'index']);
    Route::get('/collections/{slug}/entries/create', [EntryController::class, 'create']);
    Route::post('/collections/{slug}/entries', [EntryController::class, 'store']);
    Route::get('/collections/{slug}/entries/{id}', [EntryController::class, 'edit']);
    Route::post('/collections/{slug}/entries/{id}', [EntryController::class, 'update']);
    Route::post('/collections/{slug}/entries/{id}/delete', [EntryController::class, 'destroy']);

    // Media
    Route::get('/media', [MediaController::class, 'index']);
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

    // Database Settings
    Route::get('/settings/database', [DatabaseSettingsController::class, 'index']);
    Route::post('/settings/database', [DatabaseSettingsController::class, 'update']);
    Route::post('/settings/database/test', [DatabaseSettingsController::class, 'test']);
    Route::post('/settings/database/list', [DatabaseSettingsController::class, 'listDatabases']);
});

// Public Page Frontend
Route::get('/page/{slug}', [PageFrontendController::class, 'show']);

// CMS Public API Routes
Route::group(['prefix' => '/api/cms'], function () {
    Route::get('/{slug}', [ContentApiController::class, 'index']);
    Route::get('/{slug}/{id}', [ContentApiController::class, 'show']);
    Route::post('/{slug}', [ContentApiController::class, 'store']);
    Route::post('/{slug}/{id}', [ContentApiController::class, 'update']);
    Route::post('/{slug}/{id}/delete', [ContentApiController::class, 'destroy']);
});
