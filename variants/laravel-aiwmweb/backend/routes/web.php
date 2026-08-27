<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\DemoController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/login', [DemoController::class, 'login']);
Route::post('/api/connector/pair', [DemoController::class, 'completePairing'])->middleware('throttle:20,1');
Route::post('/api/logout', [DemoController::class, 'logout'])->middleware('auth');

Route::prefix('/api/tenants/{tenant}')->middleware(['auth', 'tenant.context'])->group(function () {
    Route::get('/sites', [DemoController::class, 'sites']);
    Route::post('/sites', [DemoController::class, 'createSite']);
    Route::get('/sites/{site}', [DemoController::class, 'showSite']);
    Route::patch('/sites/{site}', [DemoController::class, 'updateSite']);
    Route::delete('/sites/{site}', [DemoController::class, 'deleteSite']);
    Route::post('/sites/{site}/pairing', [DemoController::class, 'pairing']);
    Route::get('/sites/{site}/connector', [DemoController::class, 'connector']);
    Route::put('/sites/{site}/connector/scopes', [DemoController::class, 'scopes']);
    Route::post('/sites/{site}/connector/rotate', [DemoController::class, 'rotate']);
    Route::delete('/sites/{site}/connector', [DemoController::class, 'revoke']);
    Route::post('/sites/{site}/verify', [DemoController::class, 'verify']);
    Route::post('/sites/{site}/sync', [DemoController::class, 'sync']);
    Route::get('/sync-runs/{run}', [DemoController::class, 'syncStatus']);
    Route::get('/sites/{site}/content', [DemoController::class, 'content']);
    Route::post('/sites/{site}/audits', [DemoController::class, 'audit']);
    Route::get('/audits/{audit}/findings', [DemoController::class, 'findings']);
    Route::put('/ai/provider', [DemoController::class, 'configureAi']);
    Route::post('/findings/{finding}/suggestions', [DemoController::class, 'suggest']);
    Route::post('/approvals/{approval}', [DemoController::class, 'decide']);
    Route::post('/approvals/{approval}/execute', [DemoController::class, 'execute']);
    Route::post('/executions/{execution}/cancel', [DemoController::class, 'cancel']);
    Route::get('/executions/{execution}/receipt', [DemoController::class, 'receipt']);
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/context', function () {
    $context = app(TenantContext::class);
    app(TenantAuthorizer::class)->authorize('tenant.view');

    return response()->json([
        'tenant' => ['slug' => $context->tenant()->slug, 'name' => $context->tenant()->name],
    ]);
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/console', fn (string $tenant) => view('console', compact('tenant')));
