<?php

use App\Http\Controllers\ContentApiController;
use App\Http\Controllers\EmailNotificationController;
use App\Http\Controllers\LegacyNotificationReadController;
use App\Http\Controllers\PlatformReadController;
use App\Http\Controllers\SyncApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->controller(PlatformReadController::class)->group(function (): void {
    Route::get('build', 'build')->name('canonical.api.build');
    Route::get('dashboard', 'dashboard')->name('canonical.api.dashboard');
});
Route::middleware(['web', 'auth'])
    ->get('notifications', [LegacyNotificationReadController::class, 'index'])
    ->name('canonical.api.legacy-notifications');

Route::post('v1/sync/webhooks/connector', [SyncApiController::class, 'webhook']);

Route::prefix('v1/tenants/{tenant}/sites/{site}')->middleware(['web', 'tenant.context'])->group(function () {
    Route::get('content/{type}', [ContentApiController::class, 'index']);
    Route::post('content/{type}', [ContentApiController::class, 'store']);
    Route::get('content/items/{content}', [ContentApiController::class, 'show']);
    Route::patch('content/items/{content}', [ContentApiController::class, 'update']);
    Route::post('content/items/{content}/state', [ContentApiController::class, 'state']);
    Route::delete('content/items/{content}', [ContentApiController::class, 'destroy']);
    Route::post('content/bulk', [ContentApiController::class, 'bulk']);
    Route::get('content/items/{content}/revisions', [ContentApiController::class, 'revisions']);
    Route::get('content/items/{content}/revisions/compare/{from}/{to}', [ContentApiController::class, 'compareRevisions']);
    Route::post('content/items/{content}/revisions/{revision}/restore', [ContentApiController::class, 'restoreRevision']);

    Route::get('media', [ContentApiController::class, 'media']);
    Route::post('media', [ContentApiController::class, 'uploadMedia']);
    Route::patch('media/{media}', [ContentApiController::class, 'updateMedia']);
    Route::delete('media/{media}', [ContentApiController::class, 'deleteMedia']);

    Route::get('comments', [ContentApiController::class, 'comments']);
    Route::post('comments/{comment}/action', [ContentApiController::class, 'commentAction']);
    Route::post('comments/{comment}/reply', [ContentApiController::class, 'replyComment']);
    Route::post('comments/bulk', [ContentApiController::class, 'bulkComments']);

    Route::get('taxonomy', [ContentApiController::class, 'taxonomy']);
    Route::get('taxonomy/discover', [ContentApiController::class, 'discoverTaxonomy']);
    Route::post('taxonomy', [ContentApiController::class, 'createTerm']);
    Route::patch('taxonomy/{term}', [ContentApiController::class, 'updateTerm']);
    Route::delete('taxonomy/{term}', [ContentApiController::class, 'deleteTerm']);
    Route::put('content/items/{content}/taxonomy', [ContentApiController::class, 'assignTerms']);
    Route::post('taxonomy/bulk-assign', [ContentApiController::class, 'bulkAssignTerms']);

    Route::post('sync', [SyncApiController::class, 'start']);
    Route::get('sync', [SyncApiController::class, 'index']);
    Route::get('sync/runs/{run}', [SyncApiController::class, 'show']);
    Route::post('sync/runs/{run}/resume', [SyncApiController::class, 'resume']);
    Route::post('sync/items/{item}/retry', [SyncApiController::class, 'retryItem']);
    Route::get('sync/diagnostics', [SyncApiController::class, 'diagnostics']);
    Route::get('conflicts', [SyncApiController::class, 'conflicts']);
    Route::post('conflicts/{conflict}/resolve', [SyncApiController::class, 'resolveConflict']);

    Route::post('transfers/export', [ContentApiController::class, 'export']);
    Route::post('transfers/import', [ContentApiController::class, 'import']);
    Route::get('transfers/{transfer}', [ContentApiController::class, 'transfer']);
});

Route::prefix('v1/tenants/{tenant}')
    ->middleware(['auth', 'tenant.context'])
    ->group(function (): void {
        Route::get('/notifications', [EmailNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [EmailNotificationController::class, 'unreadCount']);
        Route::post('/notifications/{notification}/read', [EmailNotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [EmailNotificationController::class, 'markAllRead']);
        Route::get('/notification-preferences', [EmailNotificationController::class, 'userPreferences']);
        Route::put('/notification-preferences', [EmailNotificationController::class, 'saveUserPreference']);
        Route::get('/notification-preferences/tenant', [EmailNotificationController::class, 'tenantPreferences']);
        Route::put('/notification-preferences/tenant', [EmailNotificationController::class, 'saveTenantPreference']);
        Route::get('/email/configuration', [EmailNotificationController::class, 'configuration']);
        Route::put('/email/configuration', [EmailNotificationController::class, 'saveConfiguration']);
        Route::post('/email/configuration/diagnose', [EmailNotificationController::class, 'diagnose']);
        Route::get('/email/templates', [EmailNotificationController::class, 'templates']);
        Route::put('/email/templates/{stableId}/{locale}', [EmailNotificationController::class, 'saveTemplate']);
        Route::get('/email/deliveries', [EmailNotificationController::class, 'deliveries']);
    });
