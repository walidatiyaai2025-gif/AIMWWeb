<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\SiteDiagnosticsController;
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

final class SiteConnectionDynamicLinksTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-DF6FFA9D87';

    public function test_exact_operation_covers_the_source_dynamic_navigation_group(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/connection | /sites/{Id:guid}/operations', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_laravel_adapts_every_dynamic_source_destination_without_caller_owned_identity(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));

        $this->assertStringContainsString('href="@($"/sites/{Id}")"', $source);
        $this->assertStringContainsString("wp-admin/profile.php", $source);
        $this->assertStringContainsString('href="@($"/sites/{Id}/explorer")"', $source);
        $this->assertStringContainsString('href="@($"/module/execution?siteId={Id}")"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('siteConnectionCenterDynamicLinks', $control);
        $this->assertStringContainsString('Number(site.id) !== Number(rawSiteId)', $control);
        $this->assertStringContainsString("tenantUrl(tenantSlug, `/sites/${encodedSiteId}`)", $control);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/explorer')", $control);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/module/execution')", $control);
        $this->assertStringContainsString('wp-admin/profile.php', $control);
        $this->assertStringContainsString('safeExternalSiteUrl(site.url)', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
    }

    public function test_internal_destinations_are_existing_guarded_tenant_workspaces(): void
    {
        $site = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($site->getActionName(), '\\'));
        $this->assertSame('tenant.view,sites.view', $site->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $site->gatherMiddleware());
        $this->assertContains('tenant.context', $site->gatherMiddleware());

        $execution = Route::getRoutes()->match(Request::create('/tenants/alpha/module/execution', 'GET'));
        $this->assertSame('canonical.workspace.execution', $execution->getName());
        $this->assertSame('operations.manage,execution.view', $execution->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $execution->gatherMiddleware());
        $this->assertContains('tenant.context', $execution->gatherMiddleware());

        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $this->assertStringContainsString("r('explorer', '/explorer'", $core);
        $this->assertStringContainsString("permission: 'sites.view'", $core);
    }

    public function test_authoritative_connection_read_enforces_tenant_and_site_identity_before_links_render(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'execution.view', 'operations.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $route = Route::getRoutes()->match(
            Request::create("/api/tenants/alpha/sites/{$alphaSite->id}/connection", 'GET'),
        );
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$alphaSite->id}/connection")
            ->assertOk()
            ->assertJsonPath('site.id', $alphaSite->id);

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$betaSite->id}/connection")
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson("/api/tenants/beta/sites/{$betaSite->id}/connection")
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-dynamic-links-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
