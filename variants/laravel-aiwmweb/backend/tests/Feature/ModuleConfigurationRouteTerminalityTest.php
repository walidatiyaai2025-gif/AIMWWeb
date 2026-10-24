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

final class ModuleConfigurationRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-F90125AE87';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_module_configuration_route(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/module/configuration', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ConfigurationValidation.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_route_is_explicit_get_only_permission_guarded_and_operation_linked(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/configuration', 'GET'));

        $this->assertSame('canonical.workspace.configuration-validation', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('settings.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->post('/tenants/alpha/module/configuration')->assertStatus(405);
    }

    public function test_authorized_route_renders_real_workspace_and_authoritative_report_is_readable(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/configuration')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/configuration-validation/report')
            ->assertOk()
            ->assertJsonStructure([
                'checked_at_utc',
                'critical_count',
                'warning_count',
                'items' => [
                    '*' => ['key', 'title', 'status', 'value', 'message'],
                ],
            ]);
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/module/configuration')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/alpha/module/configuration')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['settings.manage']);
        $this->actingAs($authorized)
            ->get('/tenants/foreign/module/configuration')
            ->assertNotFound();
    }

    public function test_query_identity_cannot_override_tenant_and_route_is_read_only(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage']);
        $tenantId = $membership->tenant_id;
        $membershipCount = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
        $roleCount = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/configuration?tenant=foreign&user_id=999&membership_id=999')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame(
            $membershipCount,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
        $this->assertSame(
            $roleCount,
            Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
        );
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "module-configuration-route-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
