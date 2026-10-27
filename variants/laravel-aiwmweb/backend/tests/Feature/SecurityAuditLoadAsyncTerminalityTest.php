<?php

namespace Tests\Feature;

use App\Http\Controllers\SecurityAuditReadController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityAuditLoadAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-A152D6A7DE';

    public function test_exact_canonical_row_is_the_pending_security_audit_load_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($ledger['operations'], fn (array $row): bool => $row['operation_id'] === SecurityAuditReadController::OPERATION_ID));

        $this->assertCount(1, $matches);
        $row = $matches[0];
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('/admin/security-audit', $row['route_screen']);
        $this->assertSame('LoadAsync [LoadAsync]', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SecurityAudit.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_route_is_exact_read_only_platform_admin_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/security-audit', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertSame(SecurityAuditReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(SecurityAuditReadController::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('canonical.admin.security-audit', $route->getName());
        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('platform.admin', $middleware);
        $this->assertNotContains('tenant.context', $middleware);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_platform_admin_loads_only_active_membership_tenant_audits_and_filters_truthfully(): void
    {
        $admin = User::factory()->create(['platform_admin' => true, 'name' => 'Audit Admin', 'email' => 'admin@example.test']);
        $other = User::factory()->create(['platform_admin' => true, 'name' => 'Other Admin', 'email' => 'other@example.test']);
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $gamma = Tenant::query()->create(['name' => 'Gamma', 'slug' => 'gamma']);

        $this->membership($alpha->id, $admin->id, 'active');
        $this->membership($beta->id, $admin->id, 'inactive');
        $this->membership($gamma->id, $other->id, 'active');

        $this->audit($alpha->id, $admin->id, 'auth.login', 'session', '42', ['category' => 'Authentication', 'outcome' => 'Succeeded', 'ip' => '10.0.0.1']);
        $this->audit($alpha->id, $admin->id, 'role.denied', 'role', '9', ['category' => 'Authorization', 'outcome' => 'Blocked']);
        $this->audit($beta->id, $admin->id, 'config.changed', 'setting', 'mail', ['category' => 'Configuration', 'outcome' => 'Succeeded']);
        $this->audit($gamma->id, $other->id, 'auth.failed', 'session', '99', ['category' => 'Authentication', 'outcome' => 'Failed']);

        $this->actingAs($admin)->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.SecurityAuditReadController::OPERATION_ID.'"', false)
            ->assertSee('auth.login')
            ->assertSee('role.denied')
            ->assertDontSee('config.changed')
            ->assertDontSee('auth.failed')
            ->assertSee('Alpha');

        $this->actingAs($admin)->get('/admin/security-audit?category=Authentication&outcome=Succeeded&q=10.0.0.1')
            ->assertOk()
            ->assertSee('auth.login')
            ->assertDontSee('role.denied')
            ->assertDontSee('config.changed');

        $this->actingAs($admin)->get('/admin/security-audit?category=Authorization&outcome=Blocked')
            ->assertOk()
            ->assertSee('role.denied')
            ->assertDontSee('auth.login');
    }

    public function test_load_is_read_only_and_repeated_requests_do_not_create_audits(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->membership($tenant->id, $admin->id, 'active');
        $this->audit($tenant->id, $admin->id, 'account.viewed', 'account', '1', ['outcome' => 'Succeeded']);

        $before = DB::table('audit_events')->orderBy('id')->get()->toJson();

        $this->actingAs($admin)->get('/admin/security-audit')->assertOk();
        $this->actingAs($admin)->get('/admin/security-audit?q=account')->assertOk();

        $this->assertSame($before, DB::table('audit_events')->orderBy('id')->get()->toJson());
    }

    public function test_guest_non_admin_and_admin_without_memberships_fail_closed_or_empty(): void
    {
        $this->get('/admin/security-audit')->assertRedirect('/login');

        $member = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($member)->get('/admin/security-audit')->assertForbidden();

        $admin = User::factory()->create(['platform_admin' => true]);
        $foreignTenant = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->audit($foreignTenant->id, null, 'foreign.secret', 'system', null, ['outcome' => 'Succeeded']);

        $this->actingAs($admin)->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('No matching security events.')
            ->assertDontSee('foreign.secret');

        // There is deliberately no tenant-addressable alias: guessed foreign/cross-tenant surfaces fail closed.
        $this->actingAs($admin)->get('/tenants/foreign/admin/security-audit')->assertNotFound();
    }

    public function test_caller_cannot_override_tenant_or_actor_scope(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->membership($tenant->id, $admin->id, 'active');

        $this->actingAs($admin)->getJson('/admin/security-audit?tenant_id='.$tenant->id)->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/security-audit?actor_user_id='.$admin->id)->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/security-audit?take=201')->assertUnprocessable();
    }

    private function membership(int $tenantId, int $userId, string $status): void
    {
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function audit(int $tenantId, ?int $actorUserId, string $event, ?string $subjectType, ?string $subjectId, array $metadata): void
    {
        DB::table('audit_events')->insert([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
