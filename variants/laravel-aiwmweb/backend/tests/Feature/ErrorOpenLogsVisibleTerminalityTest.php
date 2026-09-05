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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErrorOpenLogsVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-8B3518EF80';

    public function test_exact_canonical_operation_remains_pending_until_authorized_integration(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/Error', $operation['route_screen']);
        $this->assertSame('/logs -> /logs', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Error.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Error.razor'));
        $view = file_get_contents(resource_path('views/platform/error.blade.php'));

        $this->assertStringContainsString('href="/logs"', $source);
        $this->assertStringContainsString('Open logs', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('AIMW-SYNC-89777052CB', $view);
        $this->assertStringContainsString('AIMW-CONT-85394A0E55', $view);
    }

    public function test_open_logs_reuses_the_existing_guarded_tenant_logs_alias(): void
    {
        $route = Route::getRoutes()->getByName('canonical.alias.logs');

        $this->assertNotNull($route);
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@redirect', ltrim($route->getActionName(), '\\'));
        $this->assertSame('tenants/{tenant}/logs', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertSame('operations.manage,diagnostics.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/logs', $route->defaults['workspace_target'] ?? null);
    }

    public function test_exactly_one_authorized_tenant_renders_the_real_open_logs_control_and_preserves_sibling_controls(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['operations.manage', 'diagnostics.view']);

        $this->actingAs($user)
            ->get('/Error')
            ->assertOk()
            ->assertSee('Open logs')
            ->assertSee('href="/tenants/alpha/logs"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('data-canonical-operation="AIMW-SYNC-89777052CB"', false)
            ->assertSee('data-canonical-operation="AIMW-CONT-85394A0E55"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/logs')
            ->assertRedirect('/tenants/alpha/module/logs');

        $this->actingAs($user)
            ->get('/tenants/alpha/module/logs')
            ->assertOk();
    }

    public function test_control_fails_closed_for_guest_missing_permission_and_ambiguous_authorized_tenants(): void
    {
        $this->withoutVite();

        $this->get('/Error')
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('Open logs');

        $missingPermission = User::factory()->create();
        $this->membership($missingPermission, 'alpha', ['operations.manage']);
        $this->actingAs($missingPermission)
            ->get('/Error')
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('/tenants/alpha/logs');

        $this->app['auth']->forgetGuards();
        $ambiguous = User::factory()->create();
        $this->membership($ambiguous, 'alpha-two', ['operations.manage', 'diagnostics.view']);
        $this->membership($ambiguous, 'beta-two', ['operations.manage', 'diagnostics.view']);
        $this->actingAs($ambiguous)
            ->get('/Error')
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID)
            ->assertDontSee('/tenants/alpha-two/logs')
            ->assertDontSee('/tenants/beta-two/logs');
    }

    public function test_multiple_memberships_with_only_one_logs_authority_resolve_only_that_tenant(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['operations.manage', 'diagnostics.view']);
        $this->membership($user, 'beta', ['operations.manage']);

        $this->actingAs($user)
            ->get('/Error')
            ->assertOk()
            ->assertSee('href="/tenants/alpha/logs"', false)
            ->assertDontSee('/tenants/beta/logs');
    }

    public function test_logs_destination_enforces_membership_and_permission_boundaries(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['operations.manage', 'diagnostics.view']);

        $this->actingAs($alphaUser)
            ->get('/tenants/not-a-member/logs')
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['operations.manage']);
        $this->actingAs($limited)
            ->get('/tenants/limited/logs')
            ->assertForbidden();
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
        $role = Role::query()->create(['name' => "error-open-logs-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->load('tenant');
        $context->forget();

        return $membership;
    }
}
