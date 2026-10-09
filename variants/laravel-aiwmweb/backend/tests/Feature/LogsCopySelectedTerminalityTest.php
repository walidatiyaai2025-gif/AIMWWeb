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

final class LogsCopySelectedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-77E7F0D972';

    public function test_copy_selected_contract_is_wired_to_the_real_tenant_logs_surface(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/LogsAndErrors.razor'));
        $frontend = (string) file_get_contents(resource_path('js/logs-close-details-control.tsx'));

        $this->assertStringContainsString('private async Task CopySelectedAsync()', $source);
        $this->assertStringContainsString('navigator.clipboard.writeText', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('formatSelectedLog(selected)', $frontend);
        $this->assertStringContainsString('await clipboard.writeText', $frontend);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/logs', 'GET'));
        $this->assertSame(AdminOperationsController::class.'@logs', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_copy_selected_adds_no_server_mutation_and_foreign_tenant_read_fails_closed(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/logs-close-details-control.tsx'));
        $this->assertStringNotContainsString("method: 'POST'", $frontend);
        $this->assertStringNotContainsString("method: 'PUT'", $frontend);
        $this->assertStringNotContainsString("method: 'PATCH'", $frontend);
        $this->assertStringNotContainsString("method: 'DELETE'", $frontend);

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->actingAs($alpha)->getJson('/tenants/beta/admin/logs')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "logs-copy-selected-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
        return $membership->fresh('tenant');
    }
}
