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

class CommentsBackToExplorerControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-COMM-2B682F7BEC';

    public function test_exact_canonical_operation_is_the_adapted_comments_back_to_explorer_control(): void
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
        $this->assertSame('comments', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/comments', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/CommentsManager.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_context_resolves_only_the_authenticated_tenant_site_and_matching_comments_contract(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk();

        $response
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('api.comments', '/api/v1/tenants/alpha/sites/'.$site->id.'/comments');

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'tenant_id' => $membership->tenant_id,
        ]);
    }

    public function test_guest_missing_tenant_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['content.view', 'sites.view']);
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

        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaMembership->tenant_id]);
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
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('tenant.slug', 'alpha');

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after, 'Back to Explorer navigation must not mutate authoritative Site persistence.');
    }

    public function test_production_wiring_is_read_only_fail_closed_and_scoped_to_the_claimed_control(): void
    {
        $component = (string) file_get_contents(resource_path('js/comments-back-to-explorer-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('active_site', $component);
        $this->assertStringContainsString('context.api.comments', $component);
        $this->assertStringContainsString("resolveCapability(context, commentsRoute).state !== 'enabled'", $component);
        $this->assertStringContainsString('hasExplorerPermission', $component);
        $this->assertStringContainsString("context.capabilities['explorer.view'] ?? context.capabilities.explorer", $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/explorer')", $component);
        $this->assertStringContainsString("r('explorer', '/explorer'", $core);
        $this->assertStringContainsString("permission: 'sites.view'", $core);
        $this->assertStringContainsString('CommentsBackToExplorerControl', $app);
        $this->assertStringContainsString("route.key === 'comments'", $app);
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
        $role = Role::query()->create(['name' => "comments-explorer-{$slug}-{$user->id}"]);
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
