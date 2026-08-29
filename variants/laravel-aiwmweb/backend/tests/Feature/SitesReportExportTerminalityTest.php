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

class SitesReportExportTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_row_is_the_pending_sites_export_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($ledger['operations'], fn (array $row): bool => $row['operation_id'] === 'AIMW-SYNC-D8581471A2'));

        $this->assertCount(1, $matches);
        $row = $matches[0];
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('/reports | /module/reports', $row['route_screen']);
        $this->assertSame('ExportSitesAsync [ExportSitesAsync]', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ReportsExports.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertSame('WordPress', $row['external_dependency']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_both_source_aliases_render_real_sites_control_and_csv_contains_only_active_tenant_rows(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        [$tenantB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);
        $this->siteFixture($tenantA->id, 'Alpha Site', 'https://alpha.example', 'connected');
        $this->siteFixture($tenantB->id, 'Beta Secret Site', 'https://beta-secret.example', 'disconnected');

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($memberA->user)->get($path)
                ->assertOk()
                ->assertSee('data-canonical-operation="AIMW-SYNC-D8581471A2"', false)
                ->assertSee('Sites report')
                ->assertSee('/tenants/alpha/reports/sites.csv', false)
                ->assertSee('Alpha Site')
                ->assertSee('https://alpha.example')
                ->assertSee('connected')
                ->assertDontSee('Beta Secret Site')
                ->assertDontSee('https://beta-secret.example');
        }

        $response = $this->actingAs($memberA->user)->get('/tenants/alpha/reports/sites.csv')->assertOk();
        $content = str_replace("\r\n", "\n", $response->streamedContent());

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Name,Url,Status', $content);
        $this->assertStringContainsString('Alpha Site,https://alpha.example,connected', $content);
        $this->assertStringNotContainsString('Beta Secret Site', $content);
        $this->assertStringNotContainsString('beta-secret.example', $content);
        $this->assertStringContainsString('attachment; filename=sites-report.csv', (string) $response->headers->get('content-disposition'));
    }

    public function test_sites_export_permission_fails_closed_without_hiding_reports_page(): void
    {
        [, $viewer] = $this->tenantMember('alpha', ['reports.view']);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports')
            ->assertOk()
            ->assertSee('data-canonical-operation="AIMW-SYNC-D8581471A2"', false)
            ->assertSee('CSV — reports.manage required')
            ->assertDontSee('href="/tenants/alpha/reports/sites.csv"', false);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports/sites.csv')->assertForbidden();
    }

    public function test_guest_missing_view_and_cross_tenant_sites_export_access_fail_closed(): void
    {
        [, $memberA] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        [, $memberB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);
        [, $noView] = $this->tenantMember('gamma', ['reports.manage']);

        $this->get('/tenants/alpha/reports')->assertUnauthorized();
        $this->get('/tenants/alpha/reports/sites.csv')->assertUnauthorized();
        $this->actingAs($noView->user)->get('/tenants/gamma/reports')->assertForbidden();
        $this->actingAs($memberA->user)->get('/tenants/beta/reports')->assertNotFound();
        $this->actingAs($memberA->user)->get('/tenants/beta/reports/sites.csv')->assertNotFound();
        $this->actingAs($memberB->user)->get('/tenants/alpha/reports/sites.csv')->assertNotFound();
    }

    public function test_empty_and_repeated_sites_exports_are_truthful_and_read_only(): void
    {
        [, $member] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        $before = [
            'sites' => DB::table('sites')->count(),
            'operations' => DB::table('operation_executions')->count(),
            'exports' => DB::table('report_exports')->count(),
        ];

        $this->actingAs($member->user)->get('/tenants/alpha/module/reports')
            ->assertOk()
            ->assertSee('No site rows are available for this tenant.');

        $first = $this->actingAs($member->user)->get('/tenants/alpha/reports/sites.csv')->assertOk()->streamedContent();
        $second = $this->actingAs($member->user)->get('/tenants/alpha/reports/sites.csv')->assertOk()->streamedContent();

        $expected = "\xEF\xBB\xBFName,Url,Status\n";
        $this->assertSame($expected, str_replace("\r\n", "\n", $first));
        $this->assertSame($expected, str_replace("\r\n", "\n", $second));
        $this->assertSame($before['sites'], DB::table('sites')->count());
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

    private function siteFixture(int $tenantId, string $name, string $url, string $connectionStatus): int
    {
        return DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => $connectionStatus,
            'health_state' => $connectionStatus === 'connected' ? 'healthy' : 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
