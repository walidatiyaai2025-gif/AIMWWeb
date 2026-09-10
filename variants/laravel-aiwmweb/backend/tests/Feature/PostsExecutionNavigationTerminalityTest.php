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

final class PostsExecutionNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-06CF784553';

    public function test_exact_canonical_row_is_generator_backed_adapted_posts_to_execution_navigation(): void
    {
        $payload = $this->reconciliation();
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('automation', $row['domain']);
        $this->assertSame('/module/posts', $row['route_screen']);
        $this->assertStringContainsString('/module/execution', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('focused_closure_contract', $row['reconciliation']['evidence_mode']);
        $this->assertContains(self::OPERATION_ID, $payload['validation']['focused_closure_contract_terminals']);
        $this->assertTrue((bool) ($payload['validation']['passed'] ?? false));
    }

    public function test_frontend_control_is_wired_only_to_posts_and_uses_authoritative_tenant_context(): void
    {
        $control = (string) file_get_contents(resource_path('js/posts-execution-link-control.tsx'));
        $host = (string) file_get_contents(resource_path('js/app.tsx'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/execution')", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('content.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('operations.manage')", $control);
        $this->assertStringContainsString("context.permissions.includes('execution.view')", $control);
        $this->assertStringContainsString("route.key === 'posts'", $host);
        $this->assertStringContainsString('<PostsExecutionLinkControl context={context} />', $host);
        $this->assertStringContainsString("Route::get('/module/posts', 'showSiteBound')->defaults('workspace_permissions', 'content.view')", $routes);
        $this->assertStringContainsString("Route::get('/module/execution', 'show')->defaults('workspace_permissions', 'operations.manage,execution.view')", $routes);
    }

    public function test_execution_destination_is_authenticated_permission_guarded_and_tenant_isolated(): void
    {
        Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->get('/tenants/alpha/module/execution')->assertRedirect('/login');

        $authorized = User::factory()->create();
        $this->membership($authorized, 'authorized', ['tenant.view', 'content.view', 'operations.manage', 'execution.view']);
        $this->withoutVite();
        $this->actingAs($authorized)
            ->get('/tenants/authorized/module/execution')
            ->assertOk()
            ->assertSee('id="app"', false);

        $missingOperations = User::factory()->create();
        $this->membership($missingOperations, 'missing-operations', ['tenant.view', 'content.view', 'execution.view']);
        $this->actingAs($missingOperations)
            ->get('/tenants/missing-operations/module/execution')
            ->assertForbidden();

        $missingExecution = User::factory()->create();
        $this->membership($missingExecution, 'missing-execution', ['tenant.view', 'content.view', 'operations.manage']);
        $this->actingAs($missingExecution)
            ->get('/tenants/missing-execution/module/execution')
            ->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'member-alpha', ['tenant.view', 'content.view', 'operations.manage', 'execution.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($alpha)
            ->get('/tenants/foreign/module/execution')
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "posts-execution-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
