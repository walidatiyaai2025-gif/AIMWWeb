<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingPlanAdminController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\PayPalWebhookController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

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

Route::prefix('api/v1/billing')->group(function () {
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::post('/webhooks/paypal', PayPalWebhookController::class);
    Route::middleware(['auth', 'platform.admin'])->prefix('admin')->group(function () {
        Route::get('/plans', [BillingPlanAdminController::class, 'index']);
        Route::post('/plans', [BillingPlanAdminController::class, 'store']);
        Route::put('/plans/{plan}', [BillingPlanAdminController::class, 'update']);
        Route::post('/plans/{plan}/clone', [BillingPlanAdminController::class, 'clone']);
        Route::post('/plans/{plan}/enabled', [BillingPlanAdminController::class, 'setEnabled']);
        Route::post('/plans/reorder', [BillingPlanAdminController::class, 'reorder']);
        Route::post('/plans/{plan}/retire', [BillingPlanAdminController::class, 'retire']);
    });
});
Route::middleware(['auth', 'tenant.context'])->prefix('api/v1/tenants/{tenant}/billing')->group(function () {
    Route::get('/subscription', [BillingController::class, 'current']);
    Route::post('/trial', [BillingController::class, 'trial']);
    Route::post('/checkout', [BillingController::class, 'checkout']);
    Route::post('/cancel', [BillingController::class, 'cancel']);
    Route::post('/change-plan', [BillingController::class, 'changePlan']);
    Route::get('/entitlements', [BillingController::class, 'entitlements']);
    Route::get('/usage', [BillingController::class, 'usage']);
    Route::get('/history',[BillingController::class, 'history']);
});

Route::middleware(['auth', 'tenant.context'])->group(function (): void {
    Route::get('/tenants/{tenant}/context', function () {
        $context = app(TenantContext::class);
        app(TenantAuthorizer::class)->authorize('tenant.view');

        return response()->json([
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $context->tenant()->name],
        ]);
    });

    Route::prefix('/tenants/{tenant}/admin')->controller(AdminOperationsController::class)->group(function (): void {
        Route::get('/members', 'members');
        Route::post('/members', 'addMember');
        Route::patch('/members/{membership}', 'updateMember');
        Route::delete('/members/{membership}', 'removeMember');
        Route::get('/roles', 'roles');
        Route::post('/roles', 'saveRole');
        Route::put('/roles/{role}', 'saveRole');
        Route::get('/sessions', 'sessions');
        Route::delete('/sessions/others', 'revokeOtherSessions');
        Route::delete('/sessions/{session}', 'revokeSession');
        Route::get('/settings/platform', 'platformSettings');
        Route::get('/settings', 'settings');
        Route::put('/settings', 'saveSetting');
        Route::get('/schedules', 'schedules');
        Route::post('/schedules', 'saveSchedule');
        Route::put('/schedules/{task}', 'saveSchedule');
        Route::get('/automations', 'automations');
        Route::post('/automations', 'saveAutomation');
        Route::put('/automations/{rule}', 'saveAutomation');
        Route::post('/automations/{rule}/trigger', 'triggerAutomation');
        Route::post('/automation-runs/{run}/approve', 'approveAutomation');
        Route::get('/operations', 'operations');
        Route::get('/operations/{operation}', 'operation');
        Route::post('/operations/{operation}/cancel', 'cancelOperation');
        Route::post('/operations/{operation}/retry', 'retryOperation');
        Route::get('/sync-operations', 'syncOperations');
        Route::get('/backups', 'backups');
        Route::post('/backups', 'requestBackup');
        Route::post('/backups/{backup}/approve', 'approveBackup');
        Route::post('/backups/{backup}/restore', 'requestRestore');
        Route::post('/restores/{restore}/approve', 'approveRestore');
        Route::get('/logs', 'logs');
        Route::get('/diagnostics', 'diagnostics');
        Route::post('/reports/exports', 'queueExport');
        Route::get('/reports/exports/{export}', 'export');
        Route::get('/reports/exports/{export}/download', 'downloadExport');
        Route::get('/reports/{report}', 'report');
    });
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/console', fn (string $tenant) => view('console', compact('tenant')));
