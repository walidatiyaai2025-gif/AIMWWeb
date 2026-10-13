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

final class EmailSchedulesHistoryLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-EC34E40629';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_delivery_history_navigation(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/email/schedules', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/EmailSchedules.razor', $operation['current_source']);
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

    public function test_guest_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/email/history')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.manage', 'diagnostics.view']);
        $this->actingAs($user)->get('/tenants/alpha/email/history')->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.manage']);
        $this->actingAs($limited)->get('/tenants/limited/email/history')->assertForbidden();

        $this->actingAs($user)->get('/tenants/beta/email/history')->assertNotFound();
    }

    public function test_runtime_control_is_operation_linked_tenant_derived_and_read_only(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/email-schedules-history-link-control.tsx'));

        $this->assertStringContainsString('<EmailSchedulesHistoryLinkControl context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, EMAIL_SCHEDULES_HISTORY_TARGET_PATH)', $control);
        $this->assertStringContainsString("'tenant.manage'", $control);
        $this->assertStringContainsString("'diagnostics.view'", $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "email-history-link-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
