<?php

namespace Tests\Feature;

use App\Http\Controllers\SecurityAuditReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityAuditSettingsNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-16D09482D4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_security_audit_back_to_settings_navigation(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/admin/security-audit', $operation['route_screen']);
        $this->assertSame('/settings -> /settings', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SecurityAudit.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('PENDING', $operation['migration_state']);
    }

    public function test_unique_active_tenant_with_tenant_view_gets_exact_tenant_qualified_settings_link(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->membership($admin, 'alpha', ['tenant.view']);

        $this->actingAs($admin)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('href="/tenants/alpha/settings"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Back to settings');

        $this->actingAs($admin)
            ->get('/tenants/alpha/settings')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_navigation_fails_closed_when_active_tenant_is_ambiguous_or_settings_permission_is_missing(): void
    {
        $ambiguous = User::factory()->create(['platform_admin' => true]);
        $this->membership($ambiguous, 'alpha', ['tenant.view']);
        $this->membership($ambiguous, 'beta', ['tenant.view']);

        $this->actingAs($ambiguous)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);

        $limited = User::factory()->create(['platform_admin' => true]);
        $this->membership($limited, 'limited', []);

        $this->actingAs($limited)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);

        $this->actingAs($limited)
            ->get('/tenants/limited/settings')
            ->assertForbidden();
    }

    public function test_foreign_tenant_settings_navigation_remains_404_and_control_has_no_mutation_path(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->membership($admin, 'alpha', ['tenant.view']);

        $other = User::factory()->create();
        $this->membership($other, 'beta', ['tenant.view']);

        $this->actingAs($admin)
            ->get('/tenants/beta/settings')
            ->assertNotFound();

        $controller = (string) file_get_contents(app_path('Http/Controllers/SecurityAuditReadController.php'));
        $view = (string) file_get_contents(resource_path('views/security/audit.blade.php'));

        $this->assertStringContainsString(SecurityAuditReadController::SETTINGS_NAVIGATION_OPERATION_ID, $controller);
        $this->assertStringContainsString('data-canonical-operation="{{ $settingsNavigationOperationId }}"', $view);
        $this->assertStringContainsString("'/tenants/'.rawurlencode(\$slug).'/settings'", $controller);
        $this->assertStringNotContainsString('redirect(', $controller);
        $this->assertStringNotContainsString('DB::table(\'audit_events\')->insert', $controller);
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

        if ($permissions !== []) {
            $role = Role::query()->create(['name' => 'security-audit-settings-'.$slug.'-'.$user->id]);
            foreach ($permissions as $permissionName) {
                $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
                $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
            }
            $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        }

        $context->forget();

        return $membership->fresh('tenant');
    }
}
