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

final class GlobalPostsOpenSynchronizationLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-EEFDDFFF87';

    public function test_exact_canonical_navigation_is_generator_backed_and_operation_scoped(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('/module/posts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor', $row['current_source']);
        $this->assertSame('focused_closure_contract', $row['reconciliation']['evidence_mode']);
        $this->assertContains(self::OPERATION_ID, $payload['validation']['focused_closure_contract_terminals']);
        $this->assertTrue((bool) ($payload['validation']['passed'] ?? false));
    }

    public function test_source_link_and_laravel_control_bind_real_sync_navigation_without_mutation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor'));
        $control = (string) file_get_contents(resource_path('js/global-posts-open-synchronization-link-control.tsx'));
        $host = (string) file_get_contents(resource_path('js/app.tsx'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('href="/module/sync"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, syncRoute.path)', $control);
        $this->assertStringContainsString("has('tenant.view')", $control);
        $this->assertStringContainsString("has('content.view')", $control);
        $this->assertStringContainsString("has('sync.view')", $control);
        $this->assertStringContainsString('resolveCapability(context, postsRoute)', $control);
        $this->assertStringContainsString('resolveCapability(context, syncRoute)', $control);
        $this->assertStringContainsString('<GlobalPostsOpenSynchronizationLinkControl context={context} />', $host);
        $this->assertStringContainsString("Route::get('/module/sync', 'showSiteBound')->defaults('workspace_permissions', 'content.view,sync.view')", $routes);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('useMutation', $control);
    }

    public function test_sync_route_denies_guests_unauthorized_roles_and_foreign_tenants(): void
    {
        Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->get('/tenants/alpha/module/sync')->assertRedirect('/login');

        $owner = User::factory()->create();
        $this->membership($owner, 'authorized', ['tenant.view', 'content.view', 'sync.view']);
        $this->withoutVite();
        $this->actingAs($owner)->get('/tenants/authorized/module/sync')
            ->assertOk()->assertSee('id="app"', false);

        $withoutSync = User::factory()->create();
        $this->membership($withoutSync, 'no-sync', ['tenant.view', 'content.view']);
        $this->actingAs($withoutSync)
            ->get('/tenants/no-sync/module/sync')->assertForbidden();

        $withoutContent = User::factory()->create();
        $this->membership($withoutContent, 'no-content', ['tenant.view', 'sync.view']);
        $this->actingAs($withoutContent)
            ->get('/tenants/no-content/module/sync')->assertForbidden();

        $foreignOwner = User::factory()->create();
        $this->membership($foreignOwner, 'local', ['tenant.view', 'content.view', 'sync.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($foreignOwner)
            ->get('/tenants/foreign/module/sync')->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "posts-open-sync-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
