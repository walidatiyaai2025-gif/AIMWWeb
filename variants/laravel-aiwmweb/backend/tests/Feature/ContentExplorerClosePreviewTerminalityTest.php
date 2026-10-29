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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerClosePreviewTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-FE6EE5740C';

    public function test_source_and_laravel_runtime_bind_the_exact_close_preview_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/content-explorer-preview-panel.tsx'));
        $page = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('private void ClosePreview() => _preview = null;', $source);
        $this->assertStringContainsString('@onclick="ClosePreview"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EXPLORER_CLOSE_PREVIEW_OPERATION_ID}', $control);
        $this->assertStringContainsString('onClick={onClose}', $control);
        $this->assertStringContainsString('onClick={() => setExplorerPreview(row)}', $page);
        $this->assertStringContainsString('onClose={() => setExplorerPreview(null)}', $page);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_explorer_route_preserves_authenticated_tenant_and_permission_boundaries(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/explorer', 'GET'));

        $this->assertSame('canonical.workspace.explorer', $route->getName());
        $this->assertSame('sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authoritative_explorer_preview_surface_fails_closed_across_tenants_and_sites(): void
    {
        $this->withoutVite();

        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create([
            'name' => 'Alpha Site',
            'url' => 'https://alpha.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $restricted = User::factory()->create();
        $this->membershipExisting($restricted, $alpha, ['tenant.view'], 'alpha-restricted');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $this->activate($beta);
        $betaSite = Site::query()->create([
            'name' => 'Beta Site',
            'url' => 'https://beta.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->get("/tenants/alpha/explorer?site={$alphaSite->id}")
            ->assertOk();

        $this->actingAs($restricted)
            ->get("/tenants/alpha/explorer?site={$alphaSite->id}")
            ->assertForbidden();

        $this->actingAs($manager)
            ->get("/tenants/beta/explorer?site={$betaSite->id}")
            ->assertNotFound();

        $this->actingAs($manager)
            ->get("/tenants/alpha/explorer?site={$betaSite->id}")
            ->assertNotFound();
    }

    public function test_canonical_operation_is_materialized_as_adapted_and_read_only(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/explorer', $operation['route_screen']);
        $this->assertSame('ClosePreview [ClosePreview]', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');

        return $tenant;
    }

    /** @param list<string> $permissions */
    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $this->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
