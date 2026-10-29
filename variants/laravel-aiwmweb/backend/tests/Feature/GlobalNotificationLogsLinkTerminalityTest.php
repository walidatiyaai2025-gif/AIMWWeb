<?php

namespace Tests\Feature;

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

final class GlobalNotificationLogsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-9EB09C490D';

    public function test_error_notification_exposes_only_the_tenant_scoped_logs_destination(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString("toast.tone === 'error' && toast.message.trim() && logsHref", $components);
        $this->assertStringContainsString('href={logsHref}', $components);
        $this->assertStringContainsString('View details in logs', $components);
        $this->assertStringContainsString('عرض التفاصيل في السجلات', $components);
        $this->assertStringNotContainsString('href="/logs"', $components);

        $this->assertStringContainsString(
            "ToastProvider logsHref={tenantUrl(context.tenant.slug, '/logs')}",
            $app
        );
    }

    public function test_existing_logs_alias_keeps_auth_permission_and_tenant_isolation_guards(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/logs', 'GET'));

        $this->assertSame('canonical.alias.logs', $route->getName());
        $this->assertSame('operations.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/logs', $route->defaults['workspace_target'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $allowed = User::factory()->create();
        $this->membership($allowed, 'alpha', ['operations.manage', 'diagnostics.view']);
        $this->actingAs($allowed)
            ->get('/tenants/alpha/logs')
            ->assertRedirect('/tenants/alpha/module/logs');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['diagnostics.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/logs')
            ->assertForbidden();

        $foreign = User::factory()->create();
        $this->membership($foreign, 'foreign', ['operations.manage', 'diagnostics.view']);
        $this->actingAs($foreign)
            ->get('/tenants/other/logs')
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
        $role = Role::query()->create(['name' => "global-notification-logs-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
