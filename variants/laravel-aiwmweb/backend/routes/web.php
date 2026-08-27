<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\HealthController;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/context', function () {
    $context = app(TenantContext::class);
    app(TenantAuthorizer::class)->authorize('tenant.view');

    return response()->json([
        'tenant' => ['slug' => $context->tenant()->slug, 'name' => $context->tenant()->name],
    ]);
});
