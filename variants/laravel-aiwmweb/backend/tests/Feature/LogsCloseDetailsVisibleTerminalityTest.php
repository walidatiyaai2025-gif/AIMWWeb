<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminOperationsController;
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

final class LogsCloseDetailsVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-024BB0971B';

    public function test_exact_canonical_operation_is_the_adapted_logs_close_details_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/logs | /module/logs', $operation['route_screen']);
        $this->assertSame('CloseDetails [CloseDetails]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor'));
        $frontend = file_get_contents(resource_path('js/logs-close-details-control.tsx'));
        $this->assertStringContainsString('private void CloseDetails() => _selectedLine = null;', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('onClick={() => setSelected(null)}', $frontend);
    }

    public function test_close_control_reuses_the_existing_authenticated_tenant_logs_read_authority(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/logs', 'GET'));

        $this->assertSame(AdminOperationsController::class.'@logs', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());

        $frontend = file_get_contents(resource_path('js/logs-close-details-control.tsx'));
        $this->assertStringContainsString("context.permissions.includes('operations.manage')", $frontend);
        $this->assertStringContainsString("context.permissions.includes('diagnostics.view')", $frontend);
        $this->assertStringContainsString('`/tenants/${context.tenant.slug}/admin/logs`', $frontend);
        $this->assertStringNotContainsString("method: 'POST'", $frontend);
        $this->assertStringNotContainsString("method: 'PUT'", $frontend);
        $this->assertStringNotContainsString("method: 'PATCH'", $frontend);
        $this->assertStringNotContainsString("method: 'DELETE'", $frontend);
    }

    public function test_foreign_tenant_logs_authority_fails_closed_with_404(): void
    {
        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->actingAs($alpha)
            ->getJson('/tenants/beta/admin/logs')
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
        $role = Role::query()->create(['name' => "logs-close-details-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
