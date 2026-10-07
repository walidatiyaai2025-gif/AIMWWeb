<?php

namespace Tests\Feature;

use App\Http\Controllers\ConfigurationValidationController;
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

final class ConfigurationValidationCopyReportTerminalityTest extends TestCase
{
    // Exact-head CI anchor after official CopyReportAsync parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-39BB044AF2';

    public function test_report_api_is_tenant_scoped_permission_guarded_and_canonical(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/configuration-validation/report', 'GET'));
        $this->assertSame(ConfigurationValidationController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_user_receives_sanitized_bounded_report(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/configuration-validation/report')
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonStructure(['checked_at_utc', 'critical_count', 'warning_count', 'items']);

        $json = $response->getContent();
        $this->assertStringNotContainsString(base_path(), $json);
        $this->assertStringNotContainsString(storage_path(), $json);
        $this->assertStringNotContainsString((string) config('app.key'), $json);
    }

    public function test_guest_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/configuration-validation/report')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['tenant.view']);
        $this->actingAs($limited)->getJson('/tenants/alpha/configuration-validation/report')->assertForbidden();

        $allowed = User::factory()->create();
        $this->membership($allowed, 'alpha2', ['settings.manage', 'tenant.view']);
        $this->actingAs($allowed)->getJson('/tenants/foreign/configuration-validation/report')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'config-validation-'.$slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership;
    }
}
