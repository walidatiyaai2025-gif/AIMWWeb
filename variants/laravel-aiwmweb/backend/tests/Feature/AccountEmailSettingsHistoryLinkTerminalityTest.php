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

final class AccountEmailSettingsHistoryLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-0AA71A5EF6';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_account_email_history_navigation(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/account/email-settings', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountEmailSettings.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_destination_is_the_real_guarded_tenant_delivery_history_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/email/history', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertSame('tenant.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_runtime_control_is_operation_linked_tenant_derived_and_read_only(): void
    {
        $summary = (string) file_get_contents(resource_path('js/account-email-settings-summary.tsx'));
        $control = (string) file_get_contents(resource_path('js/account-email-settings-history-link-control.tsx'));

        $this->assertStringContainsString('<AccountEmailSettingsHistoryLinkControl context={context} />', $summary);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, ACCOUNT_EMAIL_HISTORY_TARGET_PATH)', $control);
        $this->assertStringContainsString("'tenant.manage'", $control);
        $this->assertStringContainsString("'diagnostics.view'", $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed_at_destination(): void
    {
        $this->get('/tenants/alpha/email/history')->assertRedirect('/login');

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.manage', 'diagnostics.view']);
        $this->actingAs($authorized)->get('/tenants/alpha/email/history')->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.manage']);
        $this->actingAs($limited)->get('/tenants/limited/email/history')->assertForbidden();

        $this->actingAs($authorized)->get('/tenants/beta/email/history')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "account-email-history-link-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
