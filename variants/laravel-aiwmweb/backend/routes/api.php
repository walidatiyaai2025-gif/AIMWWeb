<?php

use App\Http\Controllers\EmailNotificationController;
use Illuminate\Support\Facades\Route;

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
