<?php

use App\Http\Controllers\ContentApiController;
use App\Http\Controllers\SyncApiController;
use Illuminate\Support\Facades\Route;

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
