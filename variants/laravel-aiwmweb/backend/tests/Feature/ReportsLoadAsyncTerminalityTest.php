<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsLoadAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_row_is_the_pending_reports_load_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($ledger['operations'], fn (array $row): bool => $row['operation_id'] === 'AIMW-BILL-A6E9CF63BC'));

        $this->assertCount(1, $matches);
        $row = $matches[0];
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('/reports | /module/reports', $row['route_screen']);
        $this->assertSame('LoadAsync [LoadAsync]', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ReportsExports.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_both_report_aliases_render_refresh_control_and_reload_authoritative_state(): void
    {
        [$tenant, $member] = $this->tenantMember('alpha', ['reports.view']);

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($member->user)->get($path)
                ->assertOk()
                ->assertSee('data-canonical-operation="AIMW-BILL-A6E9CF63BC"', false)
                ->assertSee('aria-label="Refresh reports"', false)
                ->assertSee('href="'.$path.'"', false)
                ->assertSee('No site rows are available for this tenant.');
        }

        $this->siteFixture($tenant->id, 'Reloaded Site');

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($member->user)->get($path)
                ->assertOk()
                ->assertSee('Reloaded Site')
                ->assertDontSee('No site rows are available for this tenant.');
        }
    }

    public function test_refresh_is_read_only_and_tenant_scoped(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('alpha', ['reports.view']);
        [$tenantB] = $this->tenantMember('beta', ['reports.view']);
        $this->siteFixture($tenantA->id, 'Alpha Site');
        $this->siteFixture($tenantB->id, 'Beta Secret Site');

        $before = [
            'sites' => DB::table('sites')->count(),
            'planner' => DB::table('content_planner_items')->count(),
            'operations' => DB::table('operation_executions')->count(),
            'exports' => DB::table('report_exports')->count(),
        ];

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($memberA->user)->get('/tenants/alpha/reports')
                ->assertOk()
                ->assertSee('Alpha Site')
                ->assertDontSee('Beta Secret Site');
        }

        $this->assertSame($before['sites'], DB::table('sites')->count());
        $this->assertSame($before['planner'], DB::table('content_planner_items')->count());
        $this->assertSame($before['operations'], DB::table('operation_executions')->count());
        $this->assertSame($before['exports'], DB::table('report_exports')->count());
    }

    public function test_guest_missing_permission_and_cross_tenant_refresh_fail_closed(): void
    {
        [, $memberA] = $this->tenantMember('alpha', ['reports.view']);
        [, $memberB] = $this->tenantMember('beta', ['reports.view']);
        [, $noView] = $this->tenantMember('gamma', []);

        $this->get('/tenants/alpha/reports')->assertRedirect('/login');
        $this->actingAs($noView->user)->get('/tenants/gamma/reports')->assertForbidden();
        $this->actingAs($memberA->user)->get('/tenants/beta/reports')->assertNotFound();
        $this->actingAs($memberB->user)->get('/tenants/alpha/module/reports')->assertNotFound();
    }

    private function tenantMember(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    private function siteFixture(int $tenantId, string $name): int
    {
        return DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example',
            'status' => 'active',
            'connection_status' => 'connected',
            'health_state' => 'healthy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
