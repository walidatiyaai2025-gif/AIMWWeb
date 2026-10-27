<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationCenterViewAllTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-470599356B';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_notification_center_view_all(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:NotificationCenter', $operation['route_screen']);
        $this->assertSame('/notifications -> /notifications [Close]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/NotificationCenter.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_runtime_view_all_is_operation_linked_tenant_scoped_and_closes(): void
    {
        $control = (string) file_get_contents(resource_path('js/notification-center-toggle-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('NOTIFICATION_CENTER_VIEW_ALL_OPERATION_ID', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/notifications')", $control);
        $this->assertStringContainsString('data-canonical-operation={NOTIFICATION_CENTER_VIEW_ALL_OPERATION_ID}', $control);
        $this->assertStringContainsString('onClick={close}', $control);
        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString('method: "POST"', $control);
    }

    public function test_notification_inbox_route_is_permission_guarded_and_foreign_tenant_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'notifications.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/notifications')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/beta/notifications')
            ->assertNotFound();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/notifications')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "notification-view-all-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
