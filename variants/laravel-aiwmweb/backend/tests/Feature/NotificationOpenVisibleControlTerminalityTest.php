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

final class NotificationOpenVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-BDF888551C';

    public function test_exact_canonical_notification_open_control_is_adapted(): void
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
        $this->assertSame('/notifications', $operation['route_screen']);
        $this->assertSame('@url -> @url', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/NotificationInbox.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $frontend = (string) file_get_contents(resource_path('js/notification-open-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('safeNotificationTarget', $frontend);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, value)', $frontend);
        $this->assertStringContainsString('<NotificationOpenControl context={context} row={row} />', $pages);
    }

    public function test_notifications_route_and_api_stay_tenant_scoped_and_foreign_tenants_fail_closed(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'notifications.view']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha/notifications')
            ->assertOk();

        $this->actingAs($alpha)
            ->getJson('/api/v1/tenants/alpha/notifications')
            ->assertOk();

        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'notifications.view']);

        $this->actingAs($beta)
            ->get('/tenants/alpha/notifications')
            ->assertNotFound();

        $this->actingAs($beta)
            ->getJson('/api/v1/tenants/alpha/notifications')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "notification-open-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
