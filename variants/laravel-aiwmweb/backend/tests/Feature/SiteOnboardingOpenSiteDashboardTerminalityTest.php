<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SiteOnboardingOpenSiteDashboardTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-BDBA0B4F01';

    public function test_server_returned_site_id_is_used_for_tenant_scoped_dashboard_navigation(): void
    {
        $source = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $source);
        $this->assertStringContainsString('siteOnboardingOpenSiteHref(context.tenant.slug, result.site.id)', $source);
        $this->assertStringContainsString("context.permissions.includes('sites.view')", $source);
        $this->assertStringContainsString('if (!Number.isSafeInteger(siteId) || siteId < 1) return null;', $source);
    }

    public function test_site_details_route_is_not_found_for_a_foreign_tenant_site(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);

        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        app(TenantContext::class)->activate($beta);
        $foreign = Site::query()->create([
            'name' => 'Other Tenant Site', 'url' => 'https://other.example.test', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotSame($alpha->id, $beta->id);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "onboarding-open-site-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
