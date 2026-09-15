<?php

use App\Authorization\TenantAuthorizer;
use App\Frontend\ActionContractRegistry;
use App\Http\Controllers\AccessDeniedReadController;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\AiPromptTemplateSaveController;
use App\Http\Controllers\AiPromptTemplatesReadController;
use App\Http\Controllers\AiProviderSettingsReadController;
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
Route::get('/access-denied', AccessDeniedReadController::class)->name('canonical.access-denied');
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
    Route::delete('/sites/{site}', [SiteManagementController::class, 'destroy'])
        ->defaults('canonical_operation_id', 'AIMW-BILL-BE4B8C3822')
        ->name('canonical.api.sites.destroy');
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
    Route::get('/tenants/{tenant}/settings/ai-prompts', AiPromptTemplatesReadController::class)
        ->name('tenant.settings.ai-prompts');
    Route::patch('/tenants/{tenant}/settings/ai-prompts/{template}', AiPromptTemplateSaveController::class)
        ->name('tenant.settings.ai-prompts.save');
    Route::get('/tenants/{tenant}/settings/ai-providers', AiProviderSettingsReadController::class)
        ->defaults('canonical_operation_id', 'AIMW-AI-58FABCCEDB')
        ->name('tenant.settings.ai-providers');

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
        ])->values();

        return response()->json([
            'user' => ['id' => (int) request()->user()->getKey(), 'name' => (string) request()->user()->name, 'email' => (string) request()->user()->email],
            'tenant' => ['slug' => (string) $context->tenant()->slug, 'name' => (string) $context->tenant()->name],
            'tenants' => $tenants,
            'permissions' => $permissions,
            'connectors' => $connectors,
            'capabilities' => [],
            'api' => [
                'sites' => "/api/tenants/{$context->tenant()->slug}/sites",
                'account.billing' => "/tenants/{$context->tenant()->slug}/route-api/billing-overview",
                'account.profile' => "/tenants/{$context->tenant()->slug}/route-api/account-profile",
            ],
            'actions' => $actionRegistry->forContext($context),
        ]);
    })->name('tenant.context');
});
