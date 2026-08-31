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

final class RuntimeErrorOpenLogsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-OPER-21EC1BDE45';

    public function test_exact_canonical_operation_is_the_pending_global_runtime_error_open_logs_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('operations', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:Routes', $operation['route_screen']);
        $this->assertSame('/logs -> /logs', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Routes.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Routes.razor'));
        $this->assertStringContainsString('<a class="btn" href="/logs">', $source);
        $this->assertStringContainsString('Open logs', $source);
    }

    public function test_global_error_boundary_wires_only_this_operation_to_the_existing_tenant_logs_alias(): void
    {
        $appSource = (string) file_get_contents(resource_path('js/app.tsx'));
        $controlSource = (string) file_get_contents(resource_path('js/runtime-error-open-logs-control.tsx'));

        $this->assertStringContainsString('import { RuntimeErrorOpenLogsControl } from \'./runtime-error-open-logs-control\';', $appSource);
        $this->assertStringContainsString('<RuntimeErrorOpenLogsControl />', $appSource);
        $this->assertStringContainsString(self::OPERATION_ID, $controlSource);
        $this->assertStringContainsString('Open logs', $controlSource);
        $this->assertStringContainsString('/^\/tenants\/([^/]+)(?:\/|$)/', $controlSource);
        $this->assertStringContainsString('`/tenants/${encodeURIComponent(tenantSlug)}/logs`', $controlSource);
        $this->assertStringNotContainsString('AIMW-CONT-8B3518EF80', $controlSource);
    }

    public function test_existing_logs_alias_is_guarded_and_redirects_authorized_tenant_to_real_logs_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/logs', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirect',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.alias.logs', $route->getName());
        $this->assertSame('operations.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/logs', $route->defaults['workspace_target'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['operations.manage', 'diagnostics.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/logs')
            ->assertRedirect('/tenants/alpha/module/logs');
    }

    public function test_logs_destination_fails_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        $this->get('/tenants/alpha/logs')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['diagnostics.view']);
        $this->actingAs($limited)->get('/tenants/limited/logs')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['operations.manage', 'diagnostics.view']);
        $this->actingAs($alpha)->get('/tenants/beta/logs')->assertNotFound();
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
        $role = Role::query()->create(['name' => "runtime-error-logs-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
