<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\AiWorkspaceRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AiWorkspaceRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-8EE4F9F6FC';

    public function test_exact_canonical_operation_is_the_adapted_ai_workspace_route(): void
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
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/ai-workspace', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/WorkspaceHub.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_guarded_and_carries_exact_operation_provenance(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/ai-workspace', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.ai-workspace', $route->getName());
        $this->assertSame('tenant.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(AiWorkspaceRouteServiceProvider::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::OPERATION_ID, AiWorkspaceRouteServiceProvider::OPERATION_ID);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_tenant_member_renders_the_real_spa_without_requiring_site_context(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/ai-workspace')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_guest_missing_permission_and_cross_tenant_direct_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/ai-workspace')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->get('/tenants/limited/ai-workspace')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view']);
        Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);
        $this->actingAs($alpha)->get('/tenants/beta/ai-workspace')->assertNotFound();
    }

    public function test_route_exposes_no_caller_supplied_site_user_or_membership_identifier(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/ai-workspace', 'GET'));

        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertStringNotContainsString('{site}', $route->uri());
        $this->assertStringNotContainsString('{user}', $route->uri());
        $this->assertStringNotContainsString('{membership}', $route->uri());
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
        $role = Role::query()->create(['name' => 'AI Workspace '.$slug.' '.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
