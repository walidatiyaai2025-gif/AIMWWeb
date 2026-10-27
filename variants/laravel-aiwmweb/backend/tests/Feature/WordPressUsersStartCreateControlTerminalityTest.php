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

final class WordPressUsersStartCreateControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-BCE1211CE3';

    public function test_exact_canonical_operation_is_adapted_to_existing_wordpress_user_dialog_launch(): void
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
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_authenticated_tenant_context_exposes_the_real_site_users_read_contract(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.edit']);
        $site = $this->site($membership);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('api.wp-users', '/api/v1/tenants/alpha/sites/'.$site->id.'/users');
    }

    public function test_guest_foreign_tenant_and_foreign_site_fail_closed_before_start_create_can_be_authoritative(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.edit']);
        $alphaSite = $this->site($alphaMembership);

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.edit']);
        $betaSite = $this->site($betaMembership);

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_production_marker_is_scoped_to_wp_users_save_action_without_domain_mutation(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'));

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString('route.key === \'wp-users\'', $components);
        $this->assertStringContainsString('actionKey === \'wordpress-users.save\'', $components);
        $this->assertStringContainsString('onAvailable(contract)', $components);
        $this->assertStringContainsString('route.controls?.map', $pages);
        $this->assertStringContainsString('OnClick="StartCreateClicked"', $source);
        $this->assertStringContainsString('StartCreate()', $source);
        $this->assertStringNotContainsString('apiRequest', substr($components, strpos($components, 'export function ActionButton'), 1800));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "users-start-create-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'WordPress Users',
            'url' => 'https://'.$membership->tenant->slug.'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
