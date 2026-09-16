<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\AccountEmailSettingsRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccountEmailSettingsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-B2CFCF818C';

    public function test_exact_canonical_operation_is_the_account_email_settings_route(): void
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
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/account/email-settings', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountEmailSettings.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_session_guarded_tenant_scoped_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/email-settings', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.account-email-settings', $route->getName());
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(AccountEmailSettingsRouteServiceProvider::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_member_renders_only_the_workspace_shell_without_email_secret_payload(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view'], 'Member');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/account/email-settings')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertDontSee('PasswordCiphertext')
            ->assertDontSee('SmtpPassword')
            ->assertDontSee('HasSavedPassword');
    }

    public function test_guest_missing_permission_and_cross_tenant_direct_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/account/email-settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/account/email-settings')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)->get('/tenants/beta/account/email-settings')->assertNotFound();
    }

    public function test_route_exposes_no_caller_supplied_user_site_provider_or_secret_identifier_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/email-settings', 'GET'));

        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertStringNotContainsString('{user}', $route->uri());
        $this->assertStringNotContainsString('{site}', $route->uri());
        $this->assertStringNotContainsString('{provider}', $route->uri());
        $this->assertStringNotContainsString('{secret}', $route->uri());
        $this->assertStringNotContainsString('{password}', $route->uri());
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
