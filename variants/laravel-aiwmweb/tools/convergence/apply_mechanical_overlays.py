#!/usr/bin/env python3
"""Apply only deterministic, mechanical overlays to an ephemeral convergence tree."""

from __future__ import annotations

import argparse
import json
import re
import subprocess
from pathlib import Path


def git_show(root: Path, ref: str, path: str) -> str:
    result = subprocess.run(
        ["git", "show", f"{ref}:{path}"], cwd=root, check=True, text=True, capture_output=True
    )
    return result.stdout


def write(root: Path, path: str, content: str) -> None:
    target = root / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(content.rstrip() + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--manifest", type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    manifest = json.loads(args.manifest.read_text(encoding="utf-8"))
    refs = {entry["role"]: entry["sha"] for entry in manifest["authorities"]}
    main_ref = manifest["main"]["sha"]

    provider = r'''<?php

namespace App\Providers;

use App\AI\AiProvider;
use App\AI\HttpAiProvider;
use App\AI\Platform\Approval\UnconfiguredPlannerApprovalGateway;
use App\AI\Platform\Approval\UnconfiguredPlannerSiteGateway;
use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Contracts\AiQuotaGateway;
use App\AI\Platform\Contracts\PlannerApprovalGateway;
use App\AI\Platform\Contracts\PlannerSiteGateway;
use App\AI\Platform\Quota\UnconfiguredAiQuotaGateway;
use App\AI\Platform\Services\AiGenerationService;
use App\Billing\Providers\BillingProvider;
use App\Billing\Providers\PayPalProvider;
use App\Connector\AdvancedWordPressGateway;
use App\Connector\HttpWordPressGateway;
use App\Connector\WordPressGateway;
use App\Content\Remote\ContentRemoteDriver;
use App\Content\Remote\DualPathContentDriver;
use App\Models\TenantSecret;
use App\Policies\TenantSecretPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(WordPressGateway::class, HttpWordPressGateway::class);
        $this->app->bind(AdvancedWordPressGateway::class, HttpWordPressGateway::class);
        $this->app->bind(AiProvider::class, HttpAiProvider::class);
        $this->app->bind(ContentRemoteDriver::class, DualPathContentDriver::class);
        $this->app->bind(BillingProvider::class, PayPalProvider::class);
        $this->app->bind(AiQuotaGateway::class, UnconfiguredAiQuotaGateway::class);
        $this->app->bind(AiGenerator::class, AiGenerationService::class);
        $this->app->bind(PlannerApprovalGateway::class, UnconfiguredPlannerApprovalGateway::class);
        $this->app->bind(PlannerSiteGateway::class, UnconfiguredPlannerSiteGateway::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
    }
}
'''
    write(root, "variants/laravel-aiwmweb/backend/app/Providers/AppServiceProvider.php", provider)

    bootstrap = r'''<?php

use App\Billing\Exceptions\BillingConflictException;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\InvalidProviderSignatureException;
use App\Billing\Exceptions\QuotaExceededException;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $proxies = array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')),
        )));

        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->append(RequestCorrelation::class);
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);
        $middleware->validateCsrfTokens(except: ['api/v1/billing/webhooks/paypal']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('health/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (EntitlementDeniedException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'ENTITLEMENT_DENIED'], 403) : null);
        $exceptions->render(fn (QuotaExceededException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'QUOTA_EXCEEDED'], 429) : null);
        $exceptions->render(fn (InvalidProviderSignatureException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => 'Invalid provider signature.', 'code' => 'INVALID_PROVIDER_SIGNATURE'], 401) : null);
        $exceptions->render(fn (BillingConflictException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'BILLING_CONFLICT'], 409) : null);
    })->create();
'''
    write(root, "variants/laravel-aiwmweb/backend/bootstrap/app.php", bootstrap)

    web = r'''<?php

use App\Authorization\TenantAuthorizer;
use App\Http\Controllers\AdminOperationsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingPlanAdminController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PayPalWebhookController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SiteDiagnosticsController;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/', fn () => view('welcome'));

Route::post('/api/login', [DemoController::class, 'login']);
Route::post('/api/connector/pair', [DemoController::class, 'completePairing'])->middleware('throttle:20,1');
Route::post('/api/logout', [DemoController::class, 'logout'])->middleware('auth');

Route::prefix('/api/tenants/{tenant}')->middleware(['auth', 'tenant.context'])->group(function (): void {
    // #260 remains canonical for Site identity and CRUD semantics.
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

    // SEO closure extends #260 without redefining Site identity.
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

    // Sites diagnostics adds observability/operations only; shared CRUD remains #260 authority.
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

Route::prefix('api/v1/billing')->group(function (): void {
    Route::get('/plans', [BillingController::class, 'plans']);
    Route::post('/webhooks/paypal', PayPalWebhookController::class);
    Route::middleware(['auth', 'platform.admin'])->prefix('admin')->group(function (): void {
        Route::get('/plans', [BillingPlanAdminController::class, 'index']);
        Route::post('/plans', [BillingPlanAdminController::class, 'store']);
        Route::put('/plans/{plan}', [BillingPlanAdminController::class, 'update']);
        Route::post('/plans/{plan}/clone', [BillingPlanAdminController::class, 'clone']);
        Route::post('/plans/{plan}/enabled', [BillingPlanAdminController::class, 'setEnabled']);
        Route::post('/plans/reorder', [BillingPlanAdminController::class, 'reorder']);
        Route::post('/plans/{plan}/retire', [BillingPlanAdminController::class, 'retire']);
    });
});
Route::middleware(['auth', 'tenant.context'])->prefix('api/v1/tenants/{tenant}/billing')->group(function (): void {
    Route::get('/subscription', [BillingController::class, 'current']);
    Route::post('/trial', [BillingController::class, 'trial']);
    Route::post('/checkout', [BillingController::class, 'checkout']);
    Route::post('/cancel', [BillingController::class, 'cancel']);
    Route::post('/change-plan', [BillingController::class, 'changePlan']);
    Route::get('/entitlements', [BillingController::class, 'entitlements']);
    Route::get('/usage', [BillingController::class, 'usage']);
    Route::get('/history', [BillingController::class, 'history']);
});

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/context', function () {
    $context = app(TenantContext::class);
    app(TenantAuthorizer::class)->authorize('tenant.view');
    $membership = $context->membership()->loadMissing('roles.permissions');
    $permissions = $membership->roles->flatMap(fn ($role) => $role->permissions)->pluck('name')->unique()->sort()->values();
    $tenants = TenantMembership::query()
        ->withoutGlobalScopes()
        ->with('tenant:id,slug,name')
        ->where('user_id', request()->user()->getKey())
        ->where('status', 'active')
        ->get()->pluck('tenant')->filter()->unique('id')->sortBy('name')->values()
        ->map(fn ($tenant) => ['slug' => $tenant->slug, 'name' => $tenant->name]);

    return response()->json([
        'user' => ['id' => request()->user()->getKey(), 'name' => request()->user()->name, 'email' => request()->user()->email],
        'tenant' => ['slug' => $context->tenant()->slug, 'name' => $context->tenant()->name],
        'tenants' => $tenants,
        'permissions' => $permissions,
        'connectors' => [],
        'capabilities' => (object) [],
        'api' => (object) [],
        'actions' => (object) [],
    ]);
});

Route::middleware(['auth', 'tenant.context'])->prefix('/tenants/{tenant}/admin')->controller(AdminOperationsController::class)->group(function (): void {
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

Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/console', fn (string $tenant) => view('console', compact('tenant')));

// Frontend catch-all MUST remain after every tenant-specific backend route.
Route::middleware(['auth', 'tenant.context'])->get('/tenants/{tenant}/{path?}', function () {
    app(TenantAuthorizer::class)->authorize('tenant.view');
    return view('app');
})->where('path', '.*');
'''
    write(root, "variants/laravel-aiwmweb/backend/routes/web.php", web)

    # Console commands are disjoint. Compose the three authoritative files mechanically.
    console_refs = [refs["production_runtime"], refs["admin_operations"], refs["billing"]]
    console_path = "variants/laravel-aiwmweb/backend/routes/console.php"
    console_sources = [git_show(root, ref, console_path) for ref in console_refs]
    imports: set[str] = set()
    for source in console_sources:
        imports.update(re.findall(r"^use [^;]+;$", source, flags=re.M))
    marker = "})->purpose('Display an inspiring quote');"
    suffixes: list[str] = []
    for source in console_sources:
        if marker not in source:
            raise RuntimeError("Unable to find canonical inspire command in console route source")
        suffix = source.split(marker, 1)[1].strip()
        if suffix:
            suffixes.append(suffix)
    inspire = "Artisan::command('inspire', function () {\n    $this->comment(Inspiring::quote());\n})->purpose('Display an inspiring quote');"
    console = "<?php\n\n" + "\n".join(sorted(imports)) + "\n\n" + inspire + "\n\n" + "\n\n".join(suffixes) + "\n"
    write(root, console_path, console)

    runtime_env_path = "variants/laravel-aiwmweb/backend/.env.example"
    runtime_env = git_show(root, refs["production_runtime"], runtime_env_path).rstrip()
    billing_env = git_show(root, refs["billing"], runtime_env_path)
    paypal_lines = [line for line in billing_env.splitlines() if line.startswith("PAYPAL_")]
    if paypal_lines:
        runtime_env += "\n\n# Billing / PayPal (no secrets committed)\n" + "\n".join(paypal_lines)
    write(root, runtime_env_path, runtime_env)

    # #269 is the advanced connector extension and contains a real plugin install/activation E2E harness.
    wp_path = "variants/laravel-aiwmweb/tests/wordpress/bootstrap-wordpress.sh"
    write(root, wp_path, git_show(root, refs["advanced_connector_extension"], wp_path))

    # Mechanical SQLite fix: explicit index names are schema-global on SQLite.
    content_migration = root / "variants/laravel-aiwmweb/backend/database/migrations/2026_08_27_210000_create_content_platform_tables.php"
    migration_text = content_migration.read_text(encoding="utf-8")
    needle = "'content_remote_unique'"
    if migration_text.count(needle) != 1:
        raise RuntimeError("Expected exactly one #263 content_remote_unique index before overlay")
    content_migration.write_text(migration_text.replace(needle, "'content_items_remote_unique'"), encoding="utf-8")

    # Laravel 13's @laravel/multiplex 0.4.3 depends on React ^19.2.7.  #262 currently
    # declares React 18, which makes a strict npm install fail ERESOLVE.  Probe the minimal
    # compatibility upgrade in the disposable tree; never use --legacy-peer-deps.
    package_path = root / "variants/laravel-aiwmweb/backend/package.json"
    package = json.loads(package_path.read_text(encoding="utf-8"))
    package.setdefault("dependencies", {})["react"] = "^19.2.7"
    package["dependencies"]["react-dom"] = "^19.2.7"
    package.setdefault("devDependencies", {})["@types/react"] = "^19.0.0"
    package["devDependencies"]["@types/react-dom"] = "^19.0.0"
    package_path.write_text(json.dumps(package, indent=2) + "\n", encoding="utf-8")

    # The acceptance ledger is generated authority from merged main. Stale manual worker edits must not win conflicts.
    for ledger_path in [
        "variants/laravel-aiwmweb/docs/CAPABILITY_PARITY_LEDGER.md",
        "variants/laravel-aiwmweb/docs/capability-parity-ledger.json",
        "variants/laravel-aiwmweb/docs/dead-function-census.json",
    ]:
        try:
            write(root, ledger_path, git_show(root, main_ref, ledger_path))
        except subprocess.CalledProcessError:
            pass

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
