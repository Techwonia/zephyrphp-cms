<?php

use ZephyrPHP\Router\Route;
use ZephyrPHP\Cms\Controllers\CmsController;
use ZephyrPHP\Cms\Controllers\CollectionController;
use ZephyrPHP\Cms\Controllers\EntryController;
use ZephyrPHP\Cms\Controllers\MediaController;
use ZephyrPHP\Cms\Controllers\DatabaseSettingsController;
use ZephyrPHP\Cms\Api\ContentApiController;

// CMS Admin Routes
Route::group(['prefix' => '/cms'], function () {
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

    // Database Settings
    Route::get('/settings/database', [DatabaseSettingsController::class, 'index']);
    Route::post('/settings/database', [DatabaseSettingsController::class, 'update']);
    Route::post('/settings/database/test', [DatabaseSettingsController::class, 'test']);
    Route::post('/settings/database/list', [DatabaseSettingsController::class, 'listDatabases']);
});

// CMS Public API Routes
Route::group(['prefix' => '/api/cms'], function () {
    Route::get('/{slug}', [ContentApiController::class, 'index']);
    Route::get('/{slug}/{id}', [ContentApiController::class, 'show']);
    Route::post('/{slug}', [ContentApiController::class, 'store']);
    Route::post('/{slug}/{id}', [ContentApiController::class, 'update']);
    Route::post('/{slug}/{id}/delete', [ContentApiController::class, 'destroy']);
});
