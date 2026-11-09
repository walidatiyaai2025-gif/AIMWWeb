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

final class BackupHistoryPagination1752Test extends TestCase
{
    use RefreshDatabase;

    public function test_backup_history_uses_tenant_scoped_sql_pages_without_writing_archives(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithPermission('backups-alpha', 'backup.manage');
        [$tenantB, $ownerB] = $this->tenantWithPermission('backups-beta', 'backup.manage');
        $this->insertHistory($tenantA, $ownerA, 103);
        $this->insertHistory($tenantB, $ownerB, 3, 'Foreign-');

        $before = DB::table('backups')->count();
        $first = $this->actingAs($ownerA)
            ->getJson('/tenants/backups-alpha/admin/backups?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 103)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 5)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('Foreign-');

        $this->assertSame('Site-102', $first->json('data.0.site_key'));
        $this->assertSame(['posts'], $first->json('data.0.manifest.objects'));
        $this->assertTrue($first->json('data.0.verification.ok'));

        $ids = collect($first->json('data'))->pluck('id')->all();
        foreach ([2 => 25, 3 => 25, 4 => 25, 5 => 3] as $page => $expected) {
            $response = $this->actingAs($ownerA)
                ->getJson("/tenants/backups-alpha/admin/backups?page={$page}&per_page=25")
                ->assertOk()
                ->assertJsonPath('total', 103)
                ->assertJsonPath('current_page', $page)
                ->assertJsonCount($expected, 'data')
                ->assertDontSee('Foreign-');
            $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->all());
        }
        $this->assertCount(103, array_unique($ids));

        $this->actingAs($ownerA)
            ->getJson('/tenants/backups-alpha/admin/backups?page=999999&per_page=25')
            ->assertOk()->assertJsonPath('current_page', 5)->assertJsonCount(3, 'data');
        $this->actingAs($ownerA)
            ->getJson('/tenants/backups-alpha/admin/backups?page=2&per_page=100')
            ->assertOk()->assertJsonPath('last_page', 2)->assertJsonCount(3, 'data');
        $this->actingAs($ownerA)
            ->getJson('/tenants/backups-alpha/admin/backups?per_page=50')
            ->assertOk()->assertJsonCount(50, 'data');

        $this->actingAs($ownerA)
            ->getJson('/tenants/backups-alpha/admin/backups')
            ->assertOk()->assertJsonCount(103, 'data')->assertJsonMissingPath('current_page');
        $this->assertSame($before, DB::table('backups')->count());
        $this->assertSame(0, DB::table('restore_requests')->count());
    }

    public function test_backup_search_is_literal_case_insensitive_and_never_searches_manifest_json(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('backups-search', 'backup.manage');
        $this->insertHistory($tenant, $owner, 26, 'Audit-');
        $this->insertHistory($tenant, $owner, 1, 'Literal%-');
        $this->insertHistory($tenant, $owner, 1, 'Literal_-');
        $this->insertHistory($tenant, $owner, 1, 'Literal!-');

        $this->actingAs($owner)
            ->getJson('/tenants/backups-search/admin/backups?search=aUdIt&page=2&per_page=10')
            ->assertOk()->assertJsonPath('total', 26)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->actingAs($owner)
            ->getJson('/tenants/backups-search/admin/backups?search=audit')
            ->assertOk()->assertJsonPath('total', 26)
            ->assertJsonPath('per_page', 25)->assertJsonCount(25, 'data');

        foreach ([
            'search=%25' => 'Literal%-000',
            'search=%5F' => 'Literal_-000',
            'search=%21' => 'Literal!-000',
        ] as $filter => $expected) {
            $this->actingAs($owner)
                ->getJson('/tenants/backups-search/admin/backups?'.$filter.'&page=1&per_page=10')
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.site_key', $expected);
        }

        $this->actingAs($owner)
            ->getJson('/tenants/backups-search/admin/backups?search=posts&page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');
        $this->actingAs($owner)
            ->getJson('/tenants/backups-search/admin/backups?search=does-not-exist&page=99&per_page=10')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(0, 'data');
    }

    public function test_backup_history_blocks_unauthorized_or_invalid_paging_requests(): void
    {
        [, $reader] = $this->tenantWithPermission('backups-reader', 'tenant.view');
        [, $owner] = $this->tenantWithPermission('backups-owner', 'backup.manage');
        $this->tenantWithPermission('backups-foreign', 'backup.manage');

        $this->actingAs($reader)
            ->getJson('/tenants/backups-reader/admin/backups?page=1&per_page=25')
            ->assertForbidden();

        foreach ([
            'page=0', 'page=1000001', 'page=abc',
            'per_page=0', 'per_page=200', 'search%5B%5D=bad&page=1',
        ] as $invalid) {
            $this->actingAs($owner)
                ->getJson('/tenants/backups-owner/admin/backups?'.$invalid)
                ->assertUnprocessable();
        }
        $this->actingAs($owner)
            ->getJson('/tenants/backups-foreign/admin/backups?page=1&per_page=25')
            ->assertNotFound();
    }

    public function test_backup_history_ui_uses_true_page_totals_and_size_controls(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/AdminOperationsController.php'));

        $this->assertStringContainsString("route.key === 'backups' ? {", $pages);
        $this->assertStringContainsString('per_page: String(backupHistoryPageSize)', $pages);
        $this->assertStringContainsString('setBackupHistoryPageSize(size)', $pages);
        $this->assertStringContainsString("route.key === 'backups' ? backupHistoryPageSize", $pages);
        $this->assertStringContainsString('paginatedBackups(', $controller);
    }

    /** @return array{Tenant, User} */
    private function tenantWithPermission(string $slug, string $permissionName): array
    {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'backup-history-reader']);
        $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }

    private function insertHistory(Tenant $tenant, User $owner, int $count, string $prefix = 'Site-'): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'requested_by_user_id' => $owner->id,
                'site_key' => sprintf('%s%03d', $prefix, $i),
                'level' => 'L1',
                'status' => 'succeeded',
                'risk_level' => 'low',
                'approval_required' => false,
                'manifest' => '{"objects":["posts"]}',
                'verification' => '{"ok":true}',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('backups')->insert($rows);
    }
}
