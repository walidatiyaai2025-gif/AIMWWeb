<?php

namespace Tests\Feature;

use App\Http\Controllers\SystemHealthReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SystemHealthRefreshTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-0DFEE862FA';

    public function test_exact_canonical_operation_matches_system_health_refresh_source_semantics(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/system-health', $operation['route_screen']);
        $this->assertStringContainsString('RefreshClickedAsync', (string) $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor'));
        $this->assertStringContainsString('OnClick="RefreshClickedAsync"', $source);
        $this->assertStringContainsString('if (isLoading) return;', $source);
        $this->assertStringContainsString('snapshot = await HealthService.CheckAsync();', $source);
        $this->assertStringContainsString('snapshot = null;', $source);
        $this->assertStringContainsString('No stale or synthetic success result is being shown.', $source);
    }

    public function test_refresh_reuses_the_real_get_only_system_health_endpoint(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health.snapshot', $route->getName());
        $this->assertSame(SystemHealthReadController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/api/tenants/alpha/system-health')->assertStatus(405);
    }

    public function test_refresh_authority_is_tenant_scoped_permission_guarded_and_secret_free(): void
    {
        $this->getJson('/api/tenants/alpha/system-health')->assertUnauthorized();

        $missingDiagnostics = User::factory()->create();
        $this->membership($missingDiagnostics, 'alpha', ['tenant.view']);
        $this->actingAs($missingDiagnostics)->getJson('/api/tenants/alpha/system-health')->assertForbidden();

        $authorized = User::factory()->create();
        $membership = $this->membership($authorized, 'owned', ['tenant.view', 'diagnostics.view']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $secretSentinel = 'system-health-refresh-secret-must-never-render';
        config()->set('services.test_only.secret', $secretSentinel);

        $response = $this->actingAs($authorized)->getJson(
            '/api/tenants/owned/system-health?tenant=foreign&user_id=999&site=999',
        );

        $response->assertOk()
            ->assertJsonPath('tenant', 'owned')
            ->assertJsonPath('checks.app.status', 'ok')
            ->assertDontSee($secretSentinel)
            ->assertDontSee('user_id')
            ->assertDontSee('site=999');

        $this->actingAs($authorized)->getJson('/api/tenants/foreign/system-health')->assertNotFound();

        $this->assertSame(
            $membershipCount,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame(
            $roleCount,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
    }

    public function test_frontend_refresh_is_visible_retryable_deduplicated_and_clears_stale_success_on_error(): void
    {
        $control = (string) file_get_contents(resource_path('js/system-health-refresh-control.tsx'));
        $workspace = (string) file_get_contents(resource_path('js/system-health-workspace.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('pending.current', $control);
        $this->assertStringContainsString('disabled={busy}', $control);
        $this->assertStringContainsString("'Checking...'", $control);
        $this->assertStringContainsString("'Run checks'", $control);
        $this->assertStringContainsString('await query.refetch();', $workspace);
        $this->assertStringContainsString('refreshing={query.isFetching}', $workspace);
        $this->assertStringContainsString('!query.error && query.data?.tenant === context.tenant.slug', $workspace);
        $this->assertStringContainsString('tone="danger"', $workspace);
        $this->assertStringContainsString('SystemHealthRefreshControl', $workspace);
    }

    public function test_evidence_claims_only_this_operation(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/system-health-refresh-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['sibling_controls_claimed'] ?? true));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "system-health-refresh-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
