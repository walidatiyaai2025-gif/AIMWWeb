<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\AdminOperationsController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
