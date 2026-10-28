<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class GlobalSynchronizationLocalContentLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-E51F091C0B';

    public function test_real_sync_source_and_explorer_destination_are_tenant_guarded(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/module/sync', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/explorer?site=7', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSiteBound', ltrim($source->getActionName(), '\\'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSiteBound', ltrim($destination->getActionName(), '\\'));
        $this->assertSame('content.view,sync.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame(['tenant'], $destination->parameterNames());

        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/global-synchronization-local-content-link-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('GlobalSynchronizationLocalContentLinkControl context={context}', $app);
        $this->assertStringContainsString('context.api.sync', $control);
        $this->assertStringContainsString('context.api.explorer', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_authorized_tenant_can_open_only_its_selected_site_local_explorer(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sync.view', 'content.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Sync Site');

        $this->actingAs($user)->get('/tenants/alpha/module/sync?site='.$site->id)->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/explorer?site='.$site->id)->assertOk();
        $context = $this->actingAs($user)->getJson('/tenants/alpha/context?site='.$site->id)->assertOk();
        $this->assertSame((int) $site->id, $context->json('active_site.id'));
        $this->assertSame("/api/v1/tenants/alpha/sites/{$site->id}/explorer", $context->json('api.explorer'));
        $this->assertSame("/api/v1/tenants/alpha/sites/{$site->id}/sync", $context->json('api.sync'));
    }

    public function test_guest_missing_permission_and_foreign_tenant_or_site_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/explorer?site=1')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sync.view', 'content.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/explorer?site='.$limitedSite->id)->assertForbidden();

        $alpha = User::factory()->create();
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view', 'sync.view', 'content.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $beta = User::factory()->create();
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view', 'sync.view', 'content.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->actingAs($alpha)->get('/tenants/beta/explorer?site='.$betaSite->id)->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/alpha/explorer?site='.$betaSite->id)->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/alpha/module/sync?site='.$betaSite->id)->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/alpha/explorer?site='.$alphaSite->id)->assertOk();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "sync-local-link-{$slug}-{$user->id}"]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
