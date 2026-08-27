<?php

use App\Authorization\TenantAuthorizer;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/context', function () {
    $context = app(TenantContext::class);
    app(TenantAuthorizer::class)->authorize('tenant.view');

    $membership = $context->membership()->loadMissing('roles.permissions');
    $permissions = $membership->roles
        ->flatMap(fn ($role) => $role->permissions)
        ->pluck('name')
        ->unique()
        ->sort()
        ->values();

    $tenants = TenantMembership::query()
        ->withoutGlobalScopes()
        ->with('tenant:id,slug,name')
        ->where('user_id', request()->user()->getKey())
        ->where('status', 'active')
        ->get()
        ->pluck('tenant')
        ->filter()
        ->unique('id')
        ->sortBy('name')
        ->values()
        ->map(fn ($tenant) => ['slug' => $tenant->slug, 'name' => $tenant->name]);

    return response()->json([
        'user' => [
            'id' => request()->user()->getKey(),
            'name' => request()->user()->name,
            'email' => request()->user()->email,
        ],
        'tenant' => [
            'slug' => $context->tenant()->slug,
            'name' => $context->tenant()->name,
        ],
        'tenants' => $tenants,
        'permissions' => $permissions,
        // Worker APIs extend these typed discovery maps. Empty means explicitly pending,
        // never "successful with demo data".
        'connectors' => [],
        'capabilities' => (object) [],
        'api' => (object) [],
        'actions' => (object) [],
    ]);
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/{path?}', function () {
    app(TenantAuthorizer::class)->authorize('tenant.view');

    return view('app');
})->where('path', '.*');
