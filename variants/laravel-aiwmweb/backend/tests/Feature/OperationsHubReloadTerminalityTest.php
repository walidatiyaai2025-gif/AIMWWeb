<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class OperationsHubReloadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-2C2CB8CBAC';

    public function test_reference_contract_reloads_current_authority_and_clears_stale_state_on_failure(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsHub.razor'));
        $this->assertStringContainsString('protected override async Task OnInitializedAsync() => await ReloadAsync();', $source);
        $this->assertStringContainsString('private async Task ReloadAsync()', $source);
        $this->assertStringContainsString('_sites = await SiteService.GetSitesAsync();', $source);
        $this->assertStringContainsString('History.GetAll(ownerUserId, ownedSiteIds, RetainedHistoryLimit)', $source);
        $this->assertStringContainsString('_sites = [];', $source);
        $this->assertStringContainsString('_recent = [];', $source);
        $this->assertStringContainsString('No metrics or stale activity are being shown as current data.', $source);

        $frontend = (string) file_get_contents(resource_path('js/operations-hub-reload-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('setSnapshot(null);', $frontend);
        $this->assertStringContainsString("context.api['operations-hub']", $frontend);
        $this->assertStringContainsString('No stale snapshot is presented as current state.', $frontend);
    }

    public function test_reload_endpoint_is_get_only_session_authenticated_and_tenant_scoped(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/operations-hub', 'GET'));

        $this->assertSame(AdminOperationsController::class.'@operationsHub', $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $contextSource = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString("'operations-hub' => \"/tenants/{\$tenant}/admin/operations-hub\"", $contextSource);
    }

    public function test_guest_missing_permissions_and_foreign_tenant_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/admin/operations-hub')->assertUnauthorized();

        $manageOnly = User::factory()->create();
        $this->membership($manageOnly, 'manage-only', ['operations.manage']);
        $this->actingAs($manageOnly)
            ->getJson('/tenants/manage-only/admin/operations-hub')
            ->assertForbidden();

        $executionOnly = User::factory()->create();
        $this->membership($executionOnly, 'execution-only', ['execution.view']);
        $this->actingAs($executionOnly)
            ->getJson('/tenants/execution-only/admin/operations-hub')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['operations.manage', 'execution.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->getJson('/tenants/beta/admin/operations-hub')
            ->assertNotFound();
    }

    public function test_authoritative_reload_returns_only_active_tenant_sites_and_safe_operation_fields(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['operations.manage', 'execution.view']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->site($alpha, 'Alpha Site', 'https://alpha.example.test');
        $this->site($beta, 'Beta Secret Site', 'https://beta.example.test');

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/admin/operations-hub')
            ->assertOk()
            ->assertJsonPath('meta.operation_id', self::OPERATION_ID)
            ->assertJsonPath('meta.tenant', 'alpha')
            ->assertJsonCount(1, 'data.sites')
            ->assertJsonPath('data.sites.0.name', 'Alpha Site');

        $response->assertDontSee('Beta Secret Site');
        $response->assertJsonStructure([
            'data' => [
                'sites' => [['id', 'name', 'status', 'connection_status', 'health_state', 'last_verified_at', 'last_sync_at']],
                'operations',
            ],
            'meta' => ['operation_id', 'tenant', 'refreshed_at'],
        ]);

        $this->withoutVite();
        $this->actingAs($user)
            ->get('/tenants/alpha/operations')
            ->assertOk();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "operations-hub-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'paired',
            'health_state' => 'healthy',
        ]);
        $context->forget();

        return $site;
    }
}
