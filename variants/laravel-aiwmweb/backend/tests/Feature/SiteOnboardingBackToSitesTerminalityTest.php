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

final class SiteOnboardingBackToSitesTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6EC0601E18';

    public function test_source_and_laravel_bind_the_exact_back_to_sites_navigation_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOnboarding.razor'),
        );
        $frontend = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));
        $focused = (string) file_get_contents(
            resource_path('js/__tests__/site-onboarding-back-to-sites-control.test.tsx'),
        );

        $this->assertStringContainsString(
            '<AppButton Href="/sites" Icon="←" Text="@(L.IsArabic ? "العودة للمواقع" : "Back to sites")" />',
            $source,
        );
        $this->assertStringContainsString(
            '<AppButton Href="/sites" Text="@(L.IsArabic ? "عرض كل المواقع" : "View all sites")" />',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/sites')", $frontend);
        $this->assertStringContainsString('data-source-control="Back to sites"', $frontend);
        $this->assertStringContainsString('data-source-control="View all sites"', $frontend);
        $this->assertStringContainsString(self::OPERATION_ID, $focused);
        $this->assertStringContainsString('expect(fetchMock).not.toHaveBeenCalled()', $focused);
    }

    public function test_foreign_tenant_onboarding_navigation_context_fails_closed(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'sites.manage']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'sites.manage']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha/sites/onboarding')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta/sites/onboarding')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "onboarding-back-sites-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
