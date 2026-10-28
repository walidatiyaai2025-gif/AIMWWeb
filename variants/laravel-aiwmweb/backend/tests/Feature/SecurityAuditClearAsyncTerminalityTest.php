<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecurityAuditClearAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-AA26ED0909';

    public function test_exact_operation_is_security_audit_clear_and_remains_read_only(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/admin/security-audit', $row['route_screen']);
        $this->assertSame('ClearAsync [ClearAsync]', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);

        $view = (string) file_get_contents(resource_path('views/security/audit.blade.php'));
        $this->assertStringContainsString('data-canonical-operation="'.self::OPERATION_ID.'"', $view);
        $this->assertStringContainsString('href="/admin/security-audit"', $view);
    }

    public function test_clear_removes_all_filters_and_rereads_real_events_with_active_tenant_isolation(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $other = User::factory()->create(['platform_admin' => true]);
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        DB::table('tenant_memberships')->insert([
            ['user_id' => $admin->id, 'tenant_id' => $alpha->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $other->id, 'tenant_id' => $beta->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([
            [$alpha->id, $admin->id, 'auth.login', 'Authentication', 'Succeeded'],
            [$alpha->id, $admin->id, 'role.denied', 'Authorization', 'Blocked'],
            [$beta->id, $other->id, 'foreign.secret', 'Authentication', 'Failed'],
        ] as [$tenantId, $userId, $event, $category, $outcome]) {
            DB::table('audit_events')->insert([
                'tenant_id' => $tenantId,
                'actor_user_id' => $userId,
                'event' => $event,
                'subject_type' => 'account',
                'subject_id' => '1',
                'metadata' => json_encode(['category' => $category, 'outcome' => $outcome], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
        }

        $before = DB::table('audit_events')->orderBy('id')->get()->toJson();

        $this->actingAs($admin)->get('/admin/security-audit?category=Authentication&outcome=Succeeded&q=auth.login')
            ->assertOk()
            ->assertSee('auth.login')
            ->assertDontSee('role.denied');

        // The Clear control navigates to the same protected GET without the
        // category, outcome or q parameters. The controller always rereads DB.
        $this->actingAs($admin)->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/admin/security-audit"', false)
            ->assertSee('auth.login')
            ->assertSee('role.denied')
            ->assertDontSee('foreign.secret');

        $this->assertSame($before, DB::table('audit_events')->orderBy('id')->get()->toJson());
    }

    public function test_clear_cannot_bypass_platform_admin_or_tenant_security(): void
    {
        $this->get('/admin/security-audit')->assertRedirect('/login');

        $member = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($member)->get('/admin/security-audit')->assertForbidden();

        $admin = User::factory()->create(['platform_admin' => true]);
        $this->actingAs($admin)->get('/tenants/foreign/admin/security-audit')->assertNotFound();
        $this->actingAs($admin)->getJson('/admin/security-audit?tenant_id=999')->assertUnprocessable();
    }
}
