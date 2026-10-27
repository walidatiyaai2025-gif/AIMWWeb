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

final class DashboardMediaNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-MEDI-799B36D113';

    public function test_media_destination_is_permission_guarded_and_tenant_isolated(): void
    {
        $this->withoutVite();

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view']);
        $this->site($alpha, 'Alpha Site', 'https://alpha.example.test');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view']);
        $this->site($beta, 'Beta Site', 'https://beta.example.test');

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/module/media')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/module/media')
            ->assertNotFound();
    }

    public function test_focused_runtime_binding_is_operation_specific_and_read_only(): void
    {
        $control = (string) file_get_contents(
            resource_path('js/dashboard-media-navigation-control.tsx'),
        );
        $host = (string) file_get_contents(resource_path('js/pages.tsx'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            'tenantUrl(context.tenant.slug, mediaRoute.path)',
            $control,
        );
        $this->assertStringContainsString(
            "mediaRoute.permission === 'content.view'",
            $control,
        );
        $this->assertStringContainsString(
            "mediaRoute.path === '/module/media'",
            $control,
        );
        $this->assertStringContainsString(
            '<DashboardMediaNavigationControl context={context} />',
            $host,
        );
        $this->assertStringContainsString(
            "Route::get('/module/media', 'showSiteBound')->defaults('workspace_permissions', 'content.view')",
            $routes,
        );
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "dashboard-media-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name, string $url): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'connected',
            'health_state' => 'healthy',
        ]);

        $context->forget();
    }
}
