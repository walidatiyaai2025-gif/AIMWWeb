<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardSitesQuickAccessTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-7957ECC794';

    public function test_exact_canonical_operation_is_dashboard_quick_access_sites_link(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> /sites', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Home.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_sites_destination_is_tenant_scoped_and_foreign_tenant_fails_closed(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'sites.view']);

        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'sites.view']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha/sites')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta/sites')
            ->assertNotFound();
    }

    public function test_runtime_binding_is_quick_access_only_and_read_only(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/dashboard-sites-quick-access-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('<DashboardSitesQuickAccessControl context={context} />', $pages);
        $this->assertStringContainsString('dashboard-quick-access-sites', $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, sitesRoute.path)', $control);
        $this->assertStringContainsString("sitesRoute.permission === 'sites.view'", $control);
        $this->assertStringContainsString('expectedSitesApi', $control);
        $this->assertStringNotContainsString('AIMW-CONT-71F54E3BA6', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "dashboard-sites-quick-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
