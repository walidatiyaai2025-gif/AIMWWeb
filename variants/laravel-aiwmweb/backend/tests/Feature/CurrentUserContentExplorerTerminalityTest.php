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

/** Canonical visible-control closure: AIMW-CONT-CEA27985CB. */
final class CurrentUserContentExplorerTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-CEA27985CB';

    public function test_exact_canonical_operation_is_the_adapted_current_user_content_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_context_resolves_only_the_authenticated_tenant_active_site_contract(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk();

        $response
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id);

        $payload = $response->json();
        $this->assertSame(
            '/api/tenants/alpha/sites/'.$site->id,
            $payload['api']['sites.detail.'.$site->id] ?? null,
        );
    }

    public function test_guest_missing_tenant_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->getJson('/tenants/limited/context?site='.$limitedSite->id)
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_explorer_navigation_rereads_authoritative_site_context_without_content_view_or_domain_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
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
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('tenant.slug', 'alpha');

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after, 'CurrentUser Content navigation must not mutate authoritative Site persistence.');
    }

    public function test_production_wiring_is_read_only_server_derived_and_scoped_to_the_claimed_control(): void
    {
        $component = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('active_site', $component);
        $this->assertStringContainsString('sites.detail.', $component);
        $this->assertStringContainsString("hasPermission(context, 'tenant.view')", $component);
        $this->assertStringContainsString("hasPermission(context, 'sites.view')", $component);
        $this->assertStringContainsString("context.capabilities['explorer.view'] ?? context.capabilities.explorer", $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/explorer')", $component);
        $this->assertStringContainsString("r('explorer', '/explorer'", $core);
        $this->assertStringContainsString("permission: 'sites.view'", $core);
        $this->assertStringNotContainsString("hasPermission(context, 'content.view')", $component);
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
        $role = Role::query()->create(['name' => "current-user-content-{$slug}-{$user->id}"]);
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
