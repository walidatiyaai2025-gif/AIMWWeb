<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\CanonicalSettingsRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SettingsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-3FD89CE024';

    public function test_source_and_laravel_route_contract_match_settings_open_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('@page "/settings"', $source);
        $this->assertStringContainsString('<PageTitle>@L["Settings"]</PageTitle>', $source);
        $this->assertStringContainsString('Settings sections', $source);
        $this->assertStringContainsString('id="language"', $source);
        $this->assertStringContainsString('id="appearance"', $source);
        $this->assertStringContainsString('id="database"', $source);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/settings', 'GET'),
        );

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.settings', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->assertStringContainsString("r('settings', '/settings'", $core);
        $this->assertStringContainsString("permission: 'tenant.view'", $core);
        $this->assertStringContainsString("if (route.key === 'settings') return", $app);
        $this->assertStringContainsString('SettingsAccessibilityAnchorControl', $app);
        $this->assertStringContainsString('SettingsLanguageAnchorControl', $app);
        $this->assertStringContainsString('SettingsAppearanceLightControl', $app);
        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('<QueryError error={query.error} retry={() => query.refetch()} />', $pages);
        $this->assertStringContainsString('No sample rows are synthesized when the server returns an empty result.', $pages);
        $this->assertSame(self::OPERATION_ID, CanonicalSettingsRouteServiceProvider::OPERATION_ID);
    }

    public function test_route_fails_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', ['tenant.view']);
        $limited = $this->member($alpha, 'limited-settings@example.test', []);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->get('/tenants/alpha/settings')
            ->assertRedirect('/login');

        $this->actingAs($limited->user)
            ->get('/tenants/alpha/settings')
            ->assertForbidden();

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/settings")
            ->assertNotFound();
    }

    public function test_authorized_get_renders_existing_settings_workspace_without_database_mutation(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', ['tenant.view']);
        $this->withoutVite();

        $before = [
            'tenants' => DB::table('tenants')->count(),
            'users' => DB::table('users')->count(),
            'tenant_memberships' => DB::table('tenant_memberships')->count(),
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
        ];

        $this->actingAs($authorized->user)
            ->get('/tenants/alpha/settings')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($authorized->user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonFragment(['tenant.view']);

        $this->assertSame($before['tenants'], DB::table('tenants')->count());
        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['tenant_memberships'], DB::table('tenant_memberships')->count());
        $this->assertSame($before['roles'], DB::table('roles')->count());
        $this->assertSame($before['permissions'], DB::table('permissions')->count());
    }

    /** @param list<string> $permissions */
    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $membership = $this->member($tenant, "{$slug}-settings@example.test", $permissions);

        return [$tenant, $membership];
    }

    /** @param list<string> $permissions */
    private function member(Tenant $tenant, string $email, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => $email]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "settings-route-{$tenant->slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }
}
