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

final class SiteDataSnapshotNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-6187F8DF8A';

    public function test_source_and_laravel_bind_the_exact_snapshot_navigation_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteDataSnapshot.razor'),
        );
        $frontend = (string) file_get_contents(resource_path('js/site-data-snapshot-sync-control.tsx'));
        $focused = (string) file_get_contents(
            resource_path('js/__tests__/site-data-snapshot-navigation-control.test.tsx'),
        );

        $this->assertStringContainsString('<AppButton Href="@($"/sites/{Id}")"', $source);
        $this->assertStringContainsString('<AppButton Href="@($"/sites/{Id}/explorer")"', $source);
        $this->assertStringContainsString('Site dashboard', $source);
        $this->assertStringContainsString('Content explorer', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('siteDataSnapshotNavigationHrefs', $frontend);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/sites/' + raw)", $frontend);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/sites/' + raw + '/explorer')", $frontend);
        $this->assertStringContainsString('data-source-control="Site dashboard"', $frontend);
        $this->assertStringContainsString('data-source-control="Content explorer"', $frontend);
        $this->assertStringContainsString(self::OPERATION_ID, $focused);
        $this->assertStringContainsString('method).toBeUndefined()', $focused);
    }

    public function test_foreign_tenant_navigation_context_fails_closed(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $alphaTenant = $this->membership($alpha, 'alpha', ['tenant.view', 'sites.view']);
        $context = app(TenantContext::class);
        $context->activate($alphaTenant);
        $site = Site::query()->create([
            'name' => 'Alpha Snapshot Site',
            'url' => 'https://alpha-snapshot.test',
            'status' => 'active',
        ]);
        $context->forget();

        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'sites.view']);

        $this->actingAs($alpha)
            ->get("/tenants/alpha/sites/{$site->id}/snapshot")
            ->assertOk();

        $this->actingAs($alpha)
            ->get("/tenants/beta/sites/{$site->id}/snapshot")
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "snapshot-navigation-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
