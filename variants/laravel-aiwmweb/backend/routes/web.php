<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingPlanAdminController;
use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\RouteApiAdapterController;
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
    Route::get('/tenants/{tenant}/context', function () {
        $context = app(TenantContext::class);
        app(TenantAuthorizer::class)->authorize('tenant.view');

        $membership = $context->membership()->loadMissing('roles.permissions');
        $permissions = $membership->roles->flatMap(fn ($role) => $role->permissions)
            ->pluck('name')->unique()->sort()->values();
        $tenants = TenantMembership::query()->withoutGlobalScopes()->with('tenant:id,slug,name')
            ->where('user_id', request()->user()->getKey())->where('status', 'active')->get()
            ->pluck('tenant')->filter()->unique('id')->sortBy('name')->values()
            ->map(fn ($tenant) => ['slug' => $tenant->slug, 'name' => $tenant->name]);
        $connectors = Connector::query()->get()->map(fn (Connector $connector) => [
            'key' => (string) $connector->identity,
            'state' => $connector->revoked_at ? 'disconnected' : ($connector->verified_at ? 'connected' : 'unknown'),
            'scopes' => $connector->enabled_scopes ?? [],
            'protocol' => $connector->protocol_version,
            'reason' => $connector->revoked_at ? 'revoked' : null,
        ])->values();
        $tenant = $context->tenant()->slug;
        $site = null;
        $activeSiteId = request()->session()->get('canonical_site_id');
        if ($activeSiteId !== null) {
            $site = Site::query()->find((int) $activeSiteId);
            if (! $site) {
                request()->session()->forget('canonical_site_id');
            }
        }

        $api = [
            'sites' => "/api/tenants/{$tenant}/sites",
            'operations' => "/tenants/{$tenant}/admin/operations",
            'automation' => "/tenants/{$tenant}/admin/automations",
            'schedules' => "/tenants/{$tenant}/admin/schedules",
            'execution' => "/tenants/{$tenant}/admin/operations",
            'site-operations' => "/tenants/{$tenant}/route-api/site-operations",
            'notifications' => "/api/v1/tenants/{$tenant}/notifications",
            'email-history' => "/api/v1/tenants/{$tenant}/email/deliveries",
            'reports' => "/tenants/{$tenant}/route-api/report-exports",
            'logs' => "/tenants/{$tenant}/admin/logs",
            'diagnostics' => "/tenants/{$tenant}/admin/diagnostics",
            'backups' => "/tenants/{$tenant}/admin/backups",
            'account.billing' => "/tenants/{$tenant}/route-api/billing-overview",
            'account.profile' => "/tenants/{$tenant}/route-api/account-profile",
            'application-users' => "/tenants/{$tenant}/admin/members",
            'roles' => "/tenants/{$tenant}/admin/roles",
            'sessions' => "/tenants/{$tenant}/admin/sessions",
        ];
        if ($site) {
            $siteId = (int) $site->getKey();
            $api += [
                'sites.detail.' . $siteId => "/api/tenants/{$tenant}/sites/{$siteId}",
                'posts' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/content/post",
                'pages' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/content/page",
                'media' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/media",
                'comments' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/comments",
                'taxonomy' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/taxonomy",
                'sync' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/sync",
                'seo-audit' => "/api/tenants/{$tenant}/sites/{$siteId}/seo/audits",
            ];
        }

        return response()->json([
            'user' => ['id' => request()->user()->getKey(), 'name' => request()->user()->name, 'email' => request()->user()->email],
            'tenant' => ['slug' => $context->tenant()->slug, 'name' => $context->tenant()->name],
            'tenants' => $tenants,
            'permissions' => $permissions,
            'connectors' => $connectors,
            'capabilities' => (object) [],
            'api' => $api,
            'active_site' => $site ? ['id' => (int) $site->getKey(), 'name' => $site->name, 'status' => $site->status] : null,
            'actions' => (object) [],
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

    Route::prefix('/tenants/{tenant}/route-api')->controller(RouteApiAdapterController::class)->group(function (): void {
        Route::get('/report-exports', 'reportExports')->name('canonical.api.report-exports');
        Route::get('/site-operations', 'siteOperations')->name('canonical.api.site-operations');
        Route::get('/billing-overview', 'billingOverview')->name('canonical.api.billing-overview');
        Route::get('/account-profile', 'accountProfile')->name('canonical.api.account-profile');
    });
});

Route::prefix('/tenants/{tenant}')
    ->middleware(['auth', 'tenant.context'])
    ->controller(CanonicalWorkspaceRouteController::class)
    ->group(function (): void {
        Route::get('/sites', 'show')->defaults('workspace_permissions', 'tenant.view,sites.view')->name('canonical.workspace.sites');
        Route::get('/sites/{site}', 'showSite')->defaults('workspace_permissions', 'tenant.view,sites.view')->whereNumber('site')->name('canonical.site.details');
        Route::get('/notifications', 'show')->defaults('workspace_permissions', 'tenant.view,notifications.view')->name('canonical.workspace.notifications');
        Route::get('/email/history', 'show')->defaults('workspace_permissions', 'tenant.manage,diagnostics.view')->name('canonical.workspace.email-history');
        Route::get('/module/backups', 'show')->defaults('workspace_permissions', 'backup.manage,backups.view')->name('canonical.workspace.backups');
        Route::get('/module/logs', 'show')->defaults('workspace_permissions', 'operations.manage,diagnostics.view')->name('canonical.workspace.logs');
        Route::get('/operations', 'show')->defaults('workspace_permissions', 'operations.manage,execution.view')->name('canonical.workspace.operations');
        Route::get('/admin/users', 'show')->defaults('workspace_permissions', 'tenant.view,users.view')->name('canonical.workspace.admin-users');
        Route::get('/account/sessions', 'show')->defaults('workspace_permissions', 'sessions.manage,sessions.view')->name('canonical.workspace.account-sessions');
        Route::get('/account/profile', 'show')->defaults('workspace_permissions', 'tenant.view')->name('canonical.workspace.account-profile');
        Route::get('/account/billing', 'show')->defaults('workspace_permissions', 'billing.view')->name('canonical.workspace.account-billing');

        Route::get('/module/posts', 'showSiteBound')->defaults('workspace_permissions', 'content.view')->name('canonical.workspace.posts');
        Route::get('/module/pages', 'showSiteBound')->defaults('workspace_permissions', 'content.view')->name('canonical.workspace.pages');
        Route::get('/module/media', 'showSiteBound')->defaults('workspace_permissions', 'content.view')->name('canonical.workspace.media');
        Route::get('/module/comments', 'showSiteBound')->defaults('workspace_permissions', 'content.view')->name('canonical.workspace.comments');
        Route::get('/module/taxonomy', 'showSiteBound')->defaults('workspace_permissions', 'content.view')->name('canonical.workspace.taxonomy');
        Route::get('/module/sync', 'showSiteBound')->defaults('workspace_permissions', 'content.view,sync.view')->name('canonical.workspace.sync');

        Route::get('/module/reports', 'show')->defaults('workspace_permissions', 'reports.view')->name('canonical.workspace.reports');
        Route::get('/site-operations', 'show')->defaults('workspace_permissions', 'execution.view')->name('canonical.workspace.site-operations');
        Route::get('/automation-center', 'show')->defaults('workspace_permissions', 'operations.manage,automation.view')->name('canonical.workspace.automation');
        Route::get('/module/schedules', 'show')->defaults('workspace_permissions', 'operations.manage,automation.view')->name('canonical.workspace.schedules');
        Route::get('/module/execution', 'show')->defaults('workspace_permissions', 'operations.manage,execution.view')->name('canonical.workspace.execution');

        Route::get('/sites/{site}/comments', 'redirectSite')->defaults('workspace_permissions', 'content.view')->defaults('workspace_target', '/module/comments')->whereNumber('site')->name('canonical.site.comments');
        Route::get('/sites/{site}/media', 'redirectSite')->defaults('workspace_permissions', 'content.view')->defaults('workspace_target', '/module/media')->whereNumber('site')->name('canonical.site.media');
        Route::get('/sites/{site}/taxonomy', 'redirectSite')->defaults('workspace_permissions', 'content.view')->defaults('workspace_target', '/module/taxonomy')->whereNumber('site')->name('canonical.site.taxonomy');

        Route::get('/admin/application-users', 'redirect')->defaults('workspace_permissions', 'tenant.view,users.view')->defaults('workspace_target', '/admin/users')->name('canonical.alias.application-users');
        Route::get('/settings/sessions', 'redirect')->defaults('workspace_permissions', 'sessions.manage,sessions.view')->defaults('workspace_target', '/account/sessions')->name('canonical.alias.settings-sessions');
        Route::get('/logs', 'redirect')->defaults('workspace_permissions', 'operations.manage,diagnostics.view')->defaults('workspace_target', '/module/logs')->name('canonical.alias.logs');
        Route::get('/operations/hub', 'redirect')->defaults('workspace_permissions', 'operations.manage,execution.view')->defaults('workspace_target', '/operations')->name('canonical.alias.operations-hub');
        Route::get('/backups', 'redirect')->defaults('workspace_permissions', 'backup.manage,backups.view')->defaults('workspace_target', '/module/backups')->name('canonical.alias.backups');
        Route::get('/reports', 'redirect')->defaults('workspace_permissions', 'reports.view')->defaults('workspace_target', '/module/reports')->name('canonical.alias.reports');
        Route::get('/operations/sites', 'redirect')->defaults('workspace_permissions', 'execution.view')->defaults('workspace_target', '/site-operations')->name('canonical.alias.operations-sites');
        Route::get('/automation-schedules', 'redirect')->defaults('workspace_permissions', 'operations.manage,automation.view')->defaults('workspace_target', '/module/schedules')->name('canonical.alias.automation-schedules');
        Route::get('/execution-center', 'redirect')->defaults('workspace_permissions', 'operations.manage,execution.view')->defaults('workspace_target', '/module/execution')->name('canonical.alias.execution-center');
    });

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/console', fn (string $tenant) => view('console', compact('tenant')));

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/{path?}', function () {
    app(TenantAuthorizer::class)->authorize('tenant.view');

    return view('app');
})->where('path', '.*');
