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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_source_semantics_are_the_system_health_refresh_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SystemHealth.razor'),
        );

        $this->assertStringContainsString('OnClick="RefreshClickedAsync"', $source);
        $this->assertStringContainsString('private Task RefreshClickedAsync(MouseEventArgs _) => RefreshAsync();', $source);
        $this->assertStringContainsString('if (isLoading) return;', $source);
        $this->assertStringContainsString('snapshot = await HealthService.CheckAsync();', $source);
        $this->assertStringContainsString('snapshot = null;', $source);
        $this->assertStringContainsString('"Run checks"', $source);
    }

    public function test_laravel_refresh_is_wired_to_the_existing_authoritative_read_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/system-health-refresh-control.tsx'));
        $workspace = (string) file_get_contents(resource_path('js/system-health-workspace.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('if (disabled || pending.current) return;', $control);
        $this->assertStringContainsString('disabled={disabled}', $control);
        $this->assertStringContainsString('aria-busy={disabled ? \'true\' : \'false\'}', $control);
        $this->assertStringContainsString('<SystemHealthRefreshControl', $workspace);
        $this->assertStringContainsString('busy={query.isFetching}', $workspace);
        $this->assertStringContainsString('onRefresh={() => query.refetch()}', $workspace);
        $this->assertStringContainsString("queryKey: ['system-health-snapshot', context.tenant.slug]", $workspace);
        $this->assertStringContainsString('query.error ?', $workspace);
        $this->assertStringContainsString('!snapshotIsAuthoritative ?', $workspace);

        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_snapshot_refresh_is_get_only_tenant_scoped_permission_guarded_and_non_mutating(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/system-health', 'GET'));

        $this->assertSame('tenant.system-health.snapshot', $route->getName());
        $this->assertSame(SystemHealthReadController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/api/tenants/alpha/system-health')->assertStatus(405);

        $this->getJson('/api/tenants/alpha/system-health')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->getJson('/api/tenants/limited/system-health')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $membership = $this->membership($authorized, 'alpha', ['tenant.view', 'diagnostics.view']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $first = $this->actingAs($authorized)
            ->getJson('/api/tenants/alpha/system-health?tenant=foreign&user_id=999&site=999')
            ->assertOk()
            ->assertJsonPath('tenant', 'alpha')
            ->assertJsonStructure([
                'tenant',
                'status',
                'checks' => ['app', 'database', 'redis', 'storage', 'queue', 'scheduler'],
            ]);

        $second = $this->actingAs($authorized)
            ->getJson('/api/tenants/alpha/system-health')
            ->assertOk()
            ->assertJsonPath('tenant', 'alpha');

        $this->actingAs($authorized)
            ->getJson('/api/tenants/foreign/system-health')
            ->assertNotFound();

        $this->assertSame(
            $membershipCount,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame(
            $roleCount,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame($first->json('tenant'), $second->json('tenant'));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
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
