<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountProfileEmailSettingsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-7A54150265';

    public function test_exact_canonical_operation_is_materialized_as_account_profile_email_settings_navigation(): void
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
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/account/profile', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountProfile.razor', $operation['current_source']);
        $this->assertStringContainsString('/account/email-settings', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_destination_are_explicit_tenant_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/account/profile', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/account/email-settings', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('tenant.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertSame('AIMW-EMAI-B2CFCF818C', $destination->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame(['tenant'], $source->parameterNames());
        $this->assertSame(['tenant'], $destination->parameterNames());
    }

    public function test_authorized_user_reaches_real_profile_and_email_settings_workspaces(): void
    {
        $user = User::factory()->create([
            'name' => 'Alpha Owner',
            'email' => 'alpha-owner@example.test',
        ]);
        $roleName = 'Owner-alpha-'.$user->id;
        $this->membership($user, 'alpha', ['tenant.view'], 'Owner');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/account/profile')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/route-api/account-profile')
            ->assertOk()
            ->assertJsonPath('data.0.user_id', $user->id)
            ->assertJsonPath('data.0.name', 'Alpha Owner')
            ->assertJsonPath('data.0.email', 'alpha-owner@example.test')
            ->assertJsonPath('data.0.roles.0', $roleName);

        $this->actingAs($user)
            ->get('/tenants/alpha/account/email-settings')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_runtime_mount_and_destination_are_bound_to_authoritative_tenant_context(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/account-profile-email-settings-link-control.tsx'));

        $this->assertStringContainsString("route.key === 'account-profile'", $pages);
        $this->assertStringContainsString('<AccountProfileEmailSettingsLinkControl context={context} />', $pages);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/account/email-settings')", $control);
        $this->assertStringContainsString('Dashboard email settings', $control);
        $this->assertStringContainsString('إعدادات بريد الداشبورد', $control);
        $this->assertStringNotContainsString('window.location', $control);
        $this->assertStringNotContainsString('/api/', $control);
    }

    public function test_guest_missing_permission_and_foreign_tenant_paths_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/account/profile')->assertRedirect('/login');
        $this->get('/tenants/alpha/account/email-settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/account/profile')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/account/email-settings')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)->get('/tenants/beta/account/profile')->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/beta/account/email-settings')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
