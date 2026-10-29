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

final class ModuleWorkspaceBackToHomeControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-371D22489E';

    public function test_exact_canonical_input_is_the_pending_module_workspace_home_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/{Key}', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor', $operation['current_source']);
        $this->assertSame('/ -> /', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_fallback_and_laravel_wiring_preserve_back_to_home_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'));
        $control = (string) file_get_contents(resource_path('js/module-workspace-back-to-home-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('data-testid="module-unavailable"', $source);
        $this->assertStringContainsString('<a class="btn" href="/">', $source);
        $this->assertStringContainsString('Back to home', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/')", $control);
        $this->assertStringContainsString("hasPermission(context, 'tenant.view')", $control);
        $this->assertStringContainsString('This capability is not available yet', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('method:', $control);

        $this->assertStringContainsString('ModuleWorkspaceBackToHomeControl', $app);
        $this->assertStringContainsString('path="module/:moduleKey"', $app);
        $this->assertStringContainsString('<ModuleWorkspaceFallbackRoute />', $app);
    }

    public function test_unknown_module_route_requires_auth_and_active_tenant_visibility(): void
    {
        $this->get('/tenants/alpha/module/not-yet-ported')->assertRedirect('/login');

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view']);

        $this->withoutVite();
        $before = Tenant::query()->withoutGlobalScopes()->findOrFail($alpha->id)->toArray();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/not-yet-ported')
            ->assertOk()
            ->assertSee('id="app"', false);

        $after = Tenant::query()->withoutGlobalScopes()->findOrFail($alpha->id)->toArray();
        $this->assertSame($before, $after, 'Module fallback navigation must remain read-only.');

        $this->actingAs($user)
            ->get('/tenants/beta/module/not-yet-ported')
            ->assertNotFound();
    }

    public function test_unknown_module_route_fails_closed_without_tenant_view(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', []);

        $this->withoutVite();
        $this->actingAs($user)
            ->get('/tenants/alpha/module/not-yet-ported')
            ->assertForbidden();
    }

    public function test_terminal_closure_evidence_is_operation_scoped_and_adapted(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/module-workspace-back-to-home-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('PENDING', $evidence['canonical_operation']['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation_claimed'] ?? null);
        $this->assertTrue((bool) ($evidence['terminality']['runtime_wired'] ?? false));
        $this->assertTrue((bool) ($evidence['terminality']['focused_tests_present'] ?? false));
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "module-back-home-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
