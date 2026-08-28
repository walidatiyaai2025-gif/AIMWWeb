<?php

use App\Authorization\TenantAuthorizer;
use App\Frontend\ActionContractRegistry;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingPlanAdminController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SiteDiagnosticsController;
use App\Http\Controllers\SiteManagementController;
use App\Models\Connector;
use App\Models\Site;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');

Route::post('/api/login', [DemoController::class, 'login']);
Route::post('/api/connector/pair', [DemoController::class, 'completePairing'])->middleware('throttle:20,1');
Route::post('/api/logout', [DemoController::class, 'logout'])->middleware('auth');

Route::prefix('/api/tenants/{tenant}')->middleware(['auth', 'tenant.context'])->group(function () {
    Route::get('/sites', [SiteManagementController::class, 'index']);
    Route::post('/sites', [SiteManagementController::class, 'store']);
    Route::get('/sites/{site}', [SiteManagementController::class, 'show']);
    Route::patch('/sites/{site}', [SiteManagementController::class, 'update']);
    Route::delete('/sites/{site}', [SiteManagementController::class, 'destroy']);
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

    Route::get('/sites/{site}/seo/audits', [SeoController::class, 'audits']);
    Route::post('/sites/{site}/seo/audits', [SeoController::class, 'startAudit']);
    Route::get('/sites/{site}/seo/audits/{audit}/findings', [SeoController::class, 'findings']);
    Route::get('/sites/{site}/seo/metadata/{type}/{remoteId}', [SeoController::class, 'metadata']);
    Route::get('/sites/{site}/seo/content/{content}/provider', [SeoController::class, 'provider']);
    Route::post('/sites/{site}/seo/findings/{finding}/prepare', [SeoController::class, 'prepare']);
    Route::post('/sites/{site}/seo/remediations/bulk', [SeoController::class, 'prepareBulk']);
    Route::post('/sites/{site}/seo/findings/{finding}/ai-proposal', [SeoController::class, 'aiProposal']);
    Route::post('/sites/{site}/seo/executions/bulk', [SeoController::class, 'executeBulk']);
    Route::post('/sites/{site}/seo/executions/{execution}/retry', [SeoController::class, 'retry']);

    Route::get('/sites/{site}/connection', [SiteDiagnosticsController::class, 'status']);
    Route::post('/sites/{site}/connection/recheck', [SiteDiagnosticsController::class, 'recheck']);
    Route::post('/sites/{site}/connection/reconnect', [SiteDiagnosticsController::class, 'reconnect']);
    Route::post('/sites/{site}/connection/disconnect', [SiteDiagnosticsController::class, 'disconnect']);
    Route::get('/sites/{site}/capabilities', [SiteDiagnosticsController::class, 'capabilities']);
    Route::get('/sites/{site}/diagnostics', [SiteDiagnosticsController::class, 'diagnosticHistory']);
    Route::get('/sites/{site}/operations', [SiteDiagnosticsController::class, 'operations']);
    Route::get('/site-operations/summary', [SiteDiagnosticsController::class, 'operationSummary']);
    Route::get('/site-operations/storage', [SiteDiagnosticsController::class, 'storage']);
    Route::post('/site-operations/cleanup/preview', [SiteDiagnosticsController::class, 'previewCleanup']);
    Route::post('/site-operations/cleanup', [SiteDiagnosticsController::class, 'cleanup']);
    Route::get('/sites-entitlements', [SiteDiagnosticsController::class, 'entitlements']);
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
    Route::get('/history', [BillingController::class, 'history']);
});

Route::middleware(['auth', 'tenant.context'])->group(function (): void {
    Route::get('/tenants/{tenant}/context', function (ActionContractRegistry $actionRegistry) {
        $context = app(TenantContext::class);
        app(TenantAuthorizer::class)->authorize('tenant.view');

        $membership = $context->membership()->loadMissing('roles.permissions');
        $permissions = $membership->roles->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')->unique()->sort()->values();
        $tenants = TenantMembership::query()->withoutGlobalScopes()->with('tenant:id,slug,name')
            ->where('user_id', request()->user()->getKey())->where('status', 'active')->get()
            ->pluck('tenant')->filter()->unique('id')->sortBy('name')->values()
            ->map(fn ($tenant) => ['id' => (int) $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name]);
        $connectors = Connector::query()->get()->map(fn (Connector $connector) => [
            'key' => (string) $connector->identity,
            'state' => $connector->revoked_at ? 'disconnected' : ($connector->verified_at ? 'connected' : 'unknown'),
            'scopes' => $connector->enabled_scopes ?? [],
            'protocol' => $connector->protocol_version,
            'reason' => $connector->revoked_at ? 'revoked' : null,
        ])->values();
        $tenantModel = $context->tenant();
        $tenant = $tenantModel->slug;
        $site = request()->has('site') ? Site::query()->findOrFail(request()->integer('site')) : null;
        $siteToken = $site ? (string) $site->id : '{site}';
        $actions = $actionRegistry->contracts($tenantModel, $permissions, $site);

        return response()->json([
            'user' => ['id' => request()->user()->getKey(), 'name' => request()->user()->name, 'email' => request()->user()->email],
            'tenant' => ['id' => (int) $tenantModel->id, 'slug' => $tenantModel->slug, 'name' => $tenantModel->name],
            'tenants' => $tenants,
            'active_site' => $site ? ['id' => (int) $site->id, 'name' => (string) $site->name] : null,
            'permissions' => $permissions,
            'connectors' => $connectors,
            'capabilities' => $actionRegistry->capabilityStates($actions),
            'api' => [
                'sites' => "/api/tenants/{$tenant}/sites",
                'posts' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/content/post",
                'pages' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/content/page",
                'media' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/media",
                'comments' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/comments",
                'taxonomy' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/taxonomy",
                'sync' => "/api/v1/tenants/{$tenant}/sites/{$siteToken}/sync",
                'seo-audit' => "/api/tenants/{$tenant}/sites/{$siteToken}/seo/audits",
                'operations' => "/tenants/{$tenant}/admin/operations",
                'notifications' => "/api/v1/tenants/{$tenant}/notifications",
                'email-history' => "/api/v1/tenants/{$tenant}/email/deliveries",
                'reports' => "/tenants/{$tenant}/admin/reports/exports",
                'logs' => "/tenants/{$tenant}/admin/logs",
                'diagnostics' => "/tenants/{$tenant}/admin/diagnostics",
                'backups' => "/tenants/{$tenant}/admin/backups",
                'account.billing' => "/api/v1/tenants/{$tenant}/billing/subscription",
                'application-users' => "/tenants/{$tenant}/admin/members",
                'roles' => "/tenants/{$tenant}/admin/roles",
                'sessions' => "/tenants/{$tenant}/admin/sessions",
            ],
            'actions' => $actions,
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

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/{path?}', function () {
    app(TenantAuthorizer::class)->authorize('tenant.view');

    return view('app');
})->where('path', '.*');
