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

final class AccountProfileEmailSettingsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-7A54150265';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_account_profile_email_settings_navigation(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/account/profile', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountProfile.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_destination_is_real_session_guarded_tenant_scoped_account_email_settings_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/account/email-settings', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.account-email-settings', $route->getName());
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(AccountEmailSettingsRouteServiceProvider::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_runtime_control_is_operation_linked_tenant_derived_and_read_only(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/account-profile-email-settings-link-control.tsx'));

        $this->assertStringContainsString('<AccountProfileEmailSettingsLinkControl context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, ACCOUNT_PROFILE_EMAIL_SETTINGS_TARGET_PATH)', $control);
        $this->assertStringContainsString("'tenant.view'", $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_guest_missing_workspace_permission_and_foreign_tenant_fail_closed_at_destination(): void
    {
        $this->get('/tenants/alpha/account/email-settings')->assertRedirect('/login');

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view']);
        $this->actingAs($authorized)->get('/tenants/alpha/account/email-settings')->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->get('/tenants/limited/account/email-settings')->assertForbidden();

        $this->actingAs($authorized)->get('/tenants/beta/account/email-settings')->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "account-profile-email-settings-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
