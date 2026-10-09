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

class ContentPlannerReportExportTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_row_is_the_pending_content_planner_export_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($ledger['operations'], fn (array $row): bool => $row['operation_id'] === 'AIMW-BILL-5E76AD4FAE'));

        $this->assertCount(1, $matches);
        $row = $matches[0];
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('billing', $row['domain']);
        $this->assertSame('/reports | /module/reports', $row['route_screen']);
        $this->assertSame('ExportPlannerAsync [ExportPlannerAsync]', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ReportsExports.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_both_report_aliases_render_real_planner_control_and_csv_is_tenant_scoped(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        [$tenantB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);
        $siteA = $this->siteFixture($tenantA->id, 'Alpha Site');
        $siteB = $this->siteFixture($tenantB->id, 'Beta Site');
        $this->plannerFixture($tenantA->id, $siteA, 'Alpha plan', null);
        $this->plannerFixture($tenantA->id, $siteA, 'Alpha scheduled', now()->addDay());
        $this->plannerFixture($tenantB->id, $siteB, 'Beta secret plan', null);

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($memberA->user)->get($path)
                ->assertOk()
                ->assertSee('data-canonical-operation="AIMW-BILL-5E76AD4FAE"', false)
                ->assertSee('Content planner report')
                ->assertSee('/tenants/alpha/reports/content-planner.csv', false)
                ->assertSee('Alpha plan')
                ->assertSee('Alpha scheduled')
                ->assertDontSee('Beta secret plan');
        }

        $response = $this->actingAs($memberA->user)->get('/tenants/alpha/reports/content-planner.csv')->assertOk();
        $content = str_replace("\r\n", "\n", $response->streamedContent());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Id,Title,Site,Status', $content);
        $this->assertStringContainsString('"Alpha plan","Alpha Site",Draft', $content);
        $this->assertStringContainsString('"Alpha scheduled","Alpha Site",Scheduled', $content);
        $this->assertStringNotContainsString('Beta secret plan', $content);
        $this->assertStringContainsString('attachment; filename=content-planner-report.csv', (string) $response->headers->get('content-disposition'));
    }

    public function test_planner_export_permission_and_cross_tenant_access_fail_closed(): void
    {
        [, $viewer] = $this->tenantMember('alpha', ['reports.view']);
        [, $memberB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports')
            ->assertOk()
            ->assertSee('data-canonical-operation="AIMW-BILL-5E76AD4FAE"', false)
            ->assertSee('CSV — reports.manage required')
            ->assertDontSee('href="/tenants/alpha/reports/content-planner.csv"', false);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports/content-planner.csv')->assertForbidden();
        $this->actingAs($memberB->user)->get('/tenants/alpha/reports/content-planner.csv')->assertNotFound();
        $this->get('/tenants/alpha/reports/content-planner.csv')->assertUnauthorized();
    }

    public function test_empty_and_repeated_exports_are_truthful_and_read_only(): void
    {
        [, $member] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        $before = [
            'planner' => DB::table('content_planner_items')->count(),
            'operations' => DB::table('operation_executions')->count(),
            'exports' => DB::table('report_exports')->count(),
        ];

        $this->actingAs($member->user)->get('/tenants/alpha/module/reports')
            ->assertOk()
            ->assertSee('No content planner rows are available for this tenant.');

        $first = $this->actingAs($member->user)->get('/tenants/alpha/reports/content-planner.csv')->assertOk()->streamedContent();
        $second = $this->actingAs($member->user)->get('/tenants/alpha/reports/content-planner.csv')->assertOk()->streamedContent();

        $expected = "\xEF\xBB\xBFId,Title,Site,Status\n";
        $this->assertSame($expected, str_replace("\r\n", "\n", $first));
        $this->assertSame($expected, str_replace("\r\n", "\n", $second));
        $this->assertSame($before['planner'], DB::table('content_planner_items')->count());
        $this->assertSame($before['operations'], DB::table('operation_executions')->count());
        $this->assertSame($before['exports'], DB::table('report_exports')->count());
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

    private function plannerFixture(int $tenantId, int $siteId, string $title, $scheduledAt): int
    {
        return DB::table('content_planner_items')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'created_by_user_id' => null,
            'title' => $title,
            'idea' => null,
            'scheduled_at' => $scheduledAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
