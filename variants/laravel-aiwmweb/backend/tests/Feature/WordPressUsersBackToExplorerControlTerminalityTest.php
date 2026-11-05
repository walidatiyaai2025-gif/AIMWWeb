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

final class WordPressUsersBackToExplorerControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-D5FFC84EC2';

    public function test_exact_canonical_operation_is_adapted_from_users_manager_back_to_explorer(): void
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
        $this->assertSame('/sites/{SiteId:guid}/users', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/UsersManager.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_context_resolves_only_authenticated_tenant_site_and_matching_wordpress_users_contract(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('api.wp-users', '/api/v1/tenants/alpha/sites/'.$site->id.'/users');
    }

    public function test_guest_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_authorized_explorer_navigation_rereads_authoritative_site_context_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/explorer?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id);

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after);
    }

    public function test_production_wiring_is_read_only_fail_closed_and_scoped_to_claimed_control(): void
    {
        $component = (string) file_get_contents(resource_path('js/wordpress-users-back-to-explorer-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('active_site', $component);
        $this->assertStringContainsString("context.api['wp-users']", $component);
        $this->assertStringContainsString("resolveCapability(context, usersRoute).state !== 'enabled'", $component);
        $this->assertStringContainsString('hasExplorerPermission', $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/explorer')", $component);
        $this->assertStringContainsString("r('wp-users', '/module/users'", $core);
        $this->assertStringContainsString("connectorScope: 'users.read'", $core);
        $this->assertStringContainsString('WordPressUsersBackToExplorerControl', $app);
        $this->assertStringContainsString("route.key === 'wp-users'", $app);
        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('method:', $component);
        $this->assertStringNotContainsString('secret', strtolower($component));
        $this->assertStringNotContainsString('success', strtolower($component));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "users-explorer-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
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
