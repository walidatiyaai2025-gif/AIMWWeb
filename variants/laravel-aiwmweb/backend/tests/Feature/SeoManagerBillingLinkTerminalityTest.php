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

final class SeoManagerBillingLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-1EA01528A9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_canonical_operation_is_materialized_as_read_only_seo_billing_navigation(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/seo', $operation['route_screen']);
        $this->assertSame('/account/billing -> /account/billing', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_authorized_seo_member_gets_server_owned_billing_link_only_with_billing_view(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view', 'billing.view']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/seo')
            ->assertOk()
            ->assertViewHas('config', static function (array $config): bool {
                return ($config['can_view_billing'] ?? null) === true
                    && ($config['urls']['billing'] ?? null) === '/tenants/alpha/account/billing';
            });

        $restricted = User::factory()->create();
        $restrictedMembership = $this->membership($restricted, 'restricted', ['tenant.view', 'seo.view']);
        $restrictedSite = $this->site($restrictedMembership, 'Restricted SEO', 'https://restricted.test');

        $this->actingAs($restricted)
            ->get('/tenants/restricted/sites/'.$restrictedSite->id.'/seo')
            ->assertOk()
            ->assertViewHas('config', static fn (array $config): bool => ($config['can_view_billing'] ?? null) === false);
    }

    public function test_existing_billing_destination_is_authenticated_tenant_scoped_and_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/billing', 'GET'));

        $this->assertSame('canonical.workspace.account-billing', $route->getName());
        $this->assertSame('billing.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_foreign_tenant_billing_destination_remains_inaccessible(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'seo.view', 'billing.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['tenant.view', 'seo.view', 'billing.view']);

        $this->actingAs($alphaUser)->get('/tenants/alpha/account/billing')->assertOk();
        $this->actingAs($alphaUser)->get('/tenants/beta/account/billing')->assertNotFound();
    }

    public function test_frontend_marker_and_fail_closed_tenant_contract_are_operation_specific(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/seo-visible-controls.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/SeoVisibleControlController.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('seoBillingHref(config)', $frontend);
        $this->assertStringContainsString('data-canonical-operation={SEO_OPERATIONS.billing}', $frontend);
        $this->assertStringContainsString("hasPermission('billing.view')", $controller);
        $this->assertStringContainsString('account/billing', $controller);
        $this->assertStringNotContainsString('paypal.com', $frontend);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'seo-billing-'.$slug.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => $url, 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
