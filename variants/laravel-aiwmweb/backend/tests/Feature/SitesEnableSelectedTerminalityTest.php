<?php

namespace Tests\Feature;

use App\Http\Controllers\SitesBulkOperationalStateController;
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

final class SitesEnableSelectedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-38D8A24B15';

    public function test_source_visible_control_is_wired_to_tenant_protected_real_endpoint(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'));
        $ui = (string) file_get_contents(resource_path('js/sites-bulk-operational-state-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('OnClick="EnableSelectedAsync"', $source);
        $this->assertStringContainsString('SetSelectedDisabledAsync(false)', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $ui);
        $this->assertStringContainsString('/bulk-enable', $ui);
        $this->assertStringContainsString('query.refetch()', $ui);
        $this->assertStringContainsString('SitesBulkOperationalStateControl', $app);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/bulk-enable', 'POST'));
        $this->assertSame(SitesBulkOperationalStateController::class.'@enable', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_enable_is_real_atomic_tenant_scoped_idempotent_and_reread(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $first = $this->site($alpha, 'Alpha One', 'disabled');
        $second = $this->site($alpha, 'Alpha Two', 'connected');
        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $foreign = $this->site($beta, 'Beta Secret', 'disabled');

        $endpoint = '/api/tenants/alpha/sites/bulk-enable';
        $this->actingAs($owner)->postJson($endpoint, ['ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonPath('requested', 2)->assertJsonPath('updated', 2)
            ->assertJsonPath('connection_status', 'unknown')
            ->assertJsonPath('operation_id', self::OPERATION_ID);

        $this->assertSame('unknown', Site::withoutGlobalScopes()->findOrFail($first->id)->connection_status);
        $this->assertSame('unknown', Site::withoutGlobalScopes()->findOrFail($second->id)->connection_status);
        $this->assertSame('disabled', Site::withoutGlobalScopes()->findOrFail($foreign->id)->connection_status);
        $this->actingAs($owner)->postJson($endpoint, ['ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonPath('updated', 0);
    }

    public function test_foreign_site_or_tenant_and_missing_permission_fail_without_any_partial_write(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $ours = $this->site($alpha, 'Ours', 'disabled');
        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $foreign = $this->site($beta, 'Foreign', 'disabled');

        $this->actingAs($owner)->postJson('/api/tenants/alpha/sites/bulk-enable', ['ids' => [$ours->id, $foreign->id]])
            ->assertNotFound();
        $this->actingAs($owner)->postJson('/api/tenants/beta/sites/bulk-enable', ['ids' => [$foreign->id]])
            ->assertNotFound();
        $this->assertSame('disabled', Site::withoutGlobalScopes()->findOrFail($ours->id)->connection_status);

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma', ['tenant.view', 'sites.view']);
        $visible = $this->site($gamma, 'Viewer', 'disabled');
        $this->actingAs($viewer)->postJson('/api/tenants/gamma/sites/bulk-enable', ['ids' => [$visible->id]])
            ->assertForbidden();
        $this->actingAs($owner)->postJson('/api/tenants/alpha/sites/bulk-enable', [
            'ids' => [$ours->id], 'tenant_id' => $beta->id, 'connection_status' => 'connected',
        ])->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->actingAs($owner)->postJson('/api/tenants/alpha/sites/bulk-enable', ['ids' => [$ours->id, $ours->id]])
            ->assertUnprocessable();
        $this->assertSame('disabled', Site::withoutGlobalScopes()->findOrFail($ours->id)->connection_status);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-bulk-enable-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
        return $tenant;
    }

    private function site(Tenant $tenant, string $name, string $connectionStatus): Site
    {
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
            'connection_status' => $connectionStatus,
        ]);
        app(TenantContext::class)->forget();
        return $site;
    }
}
