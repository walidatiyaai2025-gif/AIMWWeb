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

class ApprovalsReportExportTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_row_is_the_pending_approvals_export_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($ledger['operations'], fn (array $row): bool => $row['operation_id'] === 'AIMW-APPR-A8F5FB3762'));

        $this->assertCount(1, $matches);
        $row = $matches[0];
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('approvals', $row['domain']);
        $this->assertSame('/reports | /module/reports', $row['route_screen']);
        $this->assertSame('ExportApprovalsAsync [ExportApprovalsAsync]', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ReportsExports.razor', $row['current_source']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_both_source_aliases_render_the_real_control_and_csv_contains_only_active_tenant_rows(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        [$tenantB, $memberB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);
        $alphaApproval = $this->approvalFixture($tenantA->id, $memberA->user_id, 'Alpha Site', 'Alpha Article', 'APPROVED');
        $this->approvalFixture($tenantB->id, $memberB->user_id, 'Beta Site', 'Beta Secret Article', 'PENDING');

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($memberA->user)->get($path)
                ->assertOk()
                ->assertSee('data-canonical-operation="AIMW-APPR-A8F5FB3762"', false)
                ->assertSee('Approvals report')
                ->assertSee('/tenants/alpha/reports/approvals.csv', false)
                ->assertSee('Alpha Article')
                ->assertDontSee('Beta Secret Article');
        }

        $response = $this->actingAs($memberA->user)->get('/tenants/alpha/reports/approvals.csv')->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Id,Title,Site,Status', $content);
        $this->assertStringContainsString((string) $alphaApproval, $content);
        $this->assertStringContainsString('Alpha Article', $content);
        $this->assertStringContainsString('Alpha Site', $content);
        $this->assertStringContainsString('APPROVED', $content);
        $this->assertStringNotContainsString('Beta Secret Article', $content);
        $this->assertStringContainsString('attachment; filename=approvals-report.csv', (string) $response->headers->get('content-disposition'));
    }

    public function test_export_permission_fails_closed_without_hiding_the_read_only_reports_page(): void
    {
        [, $viewer] = $this->tenantMember('alpha', ['reports.view']);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports')
            ->assertOk()
            ->assertSee('CSV — reports.manage required')
            ->assertDontSee('href="/tenants/alpha/reports/approvals.csv"', false);

        $this->actingAs($viewer->user)->get('/tenants/alpha/reports/approvals.csv')->assertForbidden();
    }

    public function test_guest_missing_view_and_cross_tenant_access_fail_closed(): void
    {
        [, $memberA] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        [, $memberB] = $this->tenantMember('beta', ['reports.view', 'reports.manage']);
        [, $noView] = $this->tenantMember('gamma', ['reports.manage']);

        $this->get('/tenants/alpha/reports')->assertUnauthorized();
        $this->get('/tenants/alpha/reports/approvals.csv')->assertUnauthorized();
        $this->actingAs($noView->user)->get('/tenants/gamma/reports')->assertForbidden();
        $this->actingAs($memberA->user)->get('/tenants/beta/reports')->assertNotFound();
        $this->actingAs($memberA->user)->get('/tenants/beta/reports/approvals.csv')->assertNotFound();
        $this->actingAs($memberB->user)->get('/tenants/alpha/reports/approvals.csv')->assertNotFound();
    }

    public function test_empty_export_is_truthful_and_read_only(): void
    {
        [, $member] = $this->tenantMember('alpha', ['reports.view', 'reports.manage']);
        $before = [
            'approvals' => DB::table('approvals')->count(),
            'operations' => DB::table('operation_executions')->count(),
            'exports' => DB::table('report_exports')->count(),
        ];

        $page = $this->actingAs($member->user)->get('/tenants/alpha/module/reports')->assertOk();
        $page->assertSee('No approval rows are available for this tenant.');
        $csv = $this->actingAs($member->user)->get('/tenants/alpha/reports/approvals.csv')->assertOk()->streamedContent();

        $this->assertSame("\xEF\xBB\xBFId,Title,Site,Status\n", str_replace("\r\n", "\n", $csv));
        $this->assertSame($before['approvals'], DB::table('approvals')->count());
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

    private function approvalFixture(int $tenantId, int $userId, string $siteName, string $title, string $status): int
    {
        $siteId = DB::table('sites')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $siteName,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $siteName)).'.example',
            'status' => 'active',
            'connection_status' => 'connected',
            'health_state' => 'healthy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $contentId = DB::table('synced_contents')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'resource_type' => 'post',
            'remote_id' => random_int(1000, 999999),
            'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $auditId = DB::table('seo_audits')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'actor_user_id' => $userId,
            'status' => 'completed',
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $findingId = DB::table('seo_findings')->insertGetId([
            'tenant_id' => $tenantId,
            'seo_audit_id' => $auditId,
            'synced_content_id' => $contentId,
            'code' => 'title-check-'.uniqid(),
            'severity' => 'medium',
            'recommendation' => 'Review title',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $suggestionId = DB::table('suggestions')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'seo_finding_id' => $findingId,
            'synced_content_id' => $contentId,
            'actor_user_id' => $userId,
            'status' => 'ready',
            'before_state' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'proposed_state' => json_encode(['title' => $title.' improved'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('approvals')->insertGetId([
            'tenant_id' => $tenantId,
            'suggestion_id' => $suggestionId,
            'actor_user_id' => $userId,
            'status' => $status,
            'before_state' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'proposed_state' => json_encode(['title' => $title.' improved'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
