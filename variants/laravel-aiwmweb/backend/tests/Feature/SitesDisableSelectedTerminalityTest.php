<?php
namespace Tests\Feature;

use App\Http\Controllers\SitesBulkDisableSelectedController;
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

final class SitesDisableSelectedTerminalityTest extends TestCase
{
    use RefreshDatabase;
    private const OPERATION_ID = 'AIMW-SYNC-45A3B43BB9';

    public function test_source_control_wires_real_tenant_scoped_disable_and_reread(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'));
        $ui = (string) file_get_contents(resource_path('js/sites-disable-selected-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('OnClick="DisableSelectedAsync"', $source);
        $this->assertStringContainsString('SetSelectedDisabledAsync(true)', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $ui);
        $this->assertStringContainsString('query.refetch()', $ui);
        $this->assertStringContainsString('SitesDisableSelectedControl', $app);
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/bulk-disable', 'POST'));
        $this->assertSame(SitesBulkDisableSelectedController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_bulk_disable_persists_atomically_and_is_safe_to_replay(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha-disable', ['tenant.view', 'sites.view', 'sites.manage']);
        $first = $this->site($alpha, 'first-disable', 'connected');
        $second = $this->site($alpha, 'second-disable', 'unknown');
        $endpoint = '/api/tenants/alpha-disable/sites/bulk-disable';
        $this->actingAs($manager)->postJson($endpoint, ['ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('connection_status', 'disabled')
            ->assertJsonPath('updated', 2);
        $this->assertSame('disabled', Site::withoutGlobalScopes()->findOrFail($first->id)->connection_status);
        $this->assertSame('disabled', Site::withoutGlobalScopes()->findOrFail($second->id)->connection_status);
        $this->actingAs($manager)->postJson($endpoint, ['ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonPath('updated', 0);
    }

    public function test_foreign_tenant_foreign_site_and_missing_manage_permission_fail_closed(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha-disable', ['tenant.view', 'sites.view', 'sites.manage']);
        $ours = $this->site($alpha, 'ours-disable', 'connected');
        $foreignManager = User::factory()->create();
        $beta = $this->membership($foreignManager, 'beta-disable', ['tenant.view', 'sites.view', 'sites.manage']);
        $foreign = $this->site($beta, 'foreign-disable', 'connected');

        $this->actingAs($manager)->postJson('/api/tenants/alpha-disable/sites/bulk-disable', [
            'ids' => [$ours->id, $foreign->id],
        ])->assertNotFound();
        $this->actingAs($manager)->postJson('/api/tenants/beta-disable/sites/bulk-disable', [
            'ids' => [$foreign->id],
        ])->assertNotFound();
        $this->assertSame('connected', Site::withoutGlobalScopes()->findOrFail($ours->id)->connection_status);

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-disable', ['tenant.view', 'sites.view']);
        $viewerSite = $this->site($gamma, 'viewer-disable', 'connected');
        $this->actingAs($viewer)->postJson('/api/tenants/gamma-disable/sites/bulk-disable', [
            'ids' => [$viewerSite->id],
        ])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/tenants/alpha-disable/sites/bulk-disable', [
            'ids' => [$ours->id], 'tenant_id' => $beta->id, 'connection_status' => 'disabled',
        ])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->actingAs($manager)->postJson('/api/tenants/alpha-disable/sites/bulk-disable', [
            'ids' => [$ours->id, $ours->id],
        ])->assertUnprocessable();
        $this->assertSame('connected', Site::withoutGlobalScopes()->findOrFail($ours->id)->connection_status);
        $this->assertSame('connected', Site::withoutGlobalScopes()->findOrFail($foreign->id)->connection_status);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-disable-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
        return $tenant;
    }

    private function site(Tenant $tenant, string $name, string $status): Site
    {
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => $name, 'url' => 'https://'.$name.'.example.test',
            'status' => 'active', 'connection_status' => $status,
        ]);
        app(TenantContext::class)->forget();
        return $site;
    }
}
