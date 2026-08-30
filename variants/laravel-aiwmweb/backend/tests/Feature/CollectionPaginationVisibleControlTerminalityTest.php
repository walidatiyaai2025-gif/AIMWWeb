<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionPaginationVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATIONS = [
        'AIMW-SYNC-12F15A0A80', 'AIMW-SYNC-CB01197D47', 'AIMW-SYNC-DBD736FACC',
        'AIMW-SYNC-4E969573BB', 'AIMW-SYNC-F340B5445A',
        'AIMW-SYNC-112E6B9631', 'AIMW-SYNC-EF652932D6',
        'AIMW-SYNC-12C023E4CC', 'AIMW-SYNC-0EDB4AB9FC',
        'AIMW-SYNC-5CF2AC6243', 'AIMW-SYNC-C8C380E7F8',
        'AIMW-SYNC-CD6F1FB97B',
    ];

    public function test_canonical_read_controls_are_marked_on_the_real_collection_surfaces(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $rows = collect($ledger['operations'])->whereIn('operation_id', self::OPERATIONS);

        $this->assertCount(count(self::OPERATIONS), $rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['domain'] === 'sync'));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['kind'] === 'visible_control'));
        $this->assertTrue($rows->every(fn (array $row): bool => ! $row['mutation'] && $row['tenant_owned']));

        $frontend = file_get_contents(resource_path('js/pages.tsx')).file_get_contents(resource_path('js/components.tsx'));
        foreach (self::OPERATIONS as $operation) {
            $this->assertSame(1, substr_count($frontend, $operation), "{$operation} must identify exactly one production control mapping");
        }
        $this->assertStringContainsString('data-canonical-load-operation={readOperations?.load}', $frontend);
        $this->assertStringContainsString('data-canonical-refresh-operation={readOperations?.refresh}', $frontend);
        $this->assertStringContainsString('data-canonical-operation={previousOperationId}', $frontend);
        $this->assertStringContainsString('onClick={() => query.refetch()}', $frontend);
        $this->assertStringContainsString('onClick={() => onPage(page - 1)}', $frontend);
    }

    public function test_collection_reads_require_content_view_and_foreign_tenant_fails_closed(): void
    {
        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'content.view']);
        $this->withoutVite();

        $this->actingAs($authorized)->get('/tenants/alpha/content')->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/content')->assertForbidden();

        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($authorized)->get('/tenants/foreign/content')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "collection-read-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
