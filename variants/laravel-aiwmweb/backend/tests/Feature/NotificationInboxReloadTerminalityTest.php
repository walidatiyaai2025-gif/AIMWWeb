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

final class NotificationInboxReloadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-12A6FEB2FF';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_notification_reload(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/notifications', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_notification_workspace_and_context_endpoint_are_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/notifications', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertSame('tenant.view,notifications.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'notifications.view']);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('api.notifications', '/api/v1/tenants/alpha/notifications');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/notifications')
            ->assertOk();
    }

    public function test_guest_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/notifications')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/notifications')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'notifications.view']);
        $this->actingAs($alpha)->get('/tenants/beta/notifications')->assertNotFound();
        $this->actingAs($alpha)->getJson('/api/v1/tenants/beta/notifications')->assertNotFound();
    }

    public function test_runtime_reload_is_operation_linked_and_read_only(): void
    {
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString("r('notifications'", $core);
        $this->assertStringContainsString("apiKey: 'notifications'", $core);
        $this->assertStringContainsString(self::OPERATION_ID, $pages);
        $this->assertStringContainsString("route.key === 'notifications'", $pages);
        $this->assertStringContainsString('query.refetch()', $pages);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "notification-reload-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
