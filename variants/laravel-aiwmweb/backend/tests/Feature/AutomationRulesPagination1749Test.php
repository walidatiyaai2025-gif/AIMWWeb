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

final class AutomationRulesPagination1749Test extends TestCase
{
    use RefreshDatabase;

    public function test_rules_have_authoritative_sql_pages_without_cross_tenant_counts_or_mutations(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithPermissions('automation-alpha', ['operations.manage']);
        [$tenantB, $ownerB] = $this->tenantWithPermissions('automation-beta', ['operations.manage']);
        $this->insertRules($tenantA, $ownerA, 103);
        $this->insertRules($tenantB, $ownerB, 3, 'Foreign');

        $before = DB::table('automation_rules')->count();
        $first = $this->actingAs($ownerA)
            ->getJson('/tenants/automation-alpha/admin/automations?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 103)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 5)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('Foreign');

        $this->assertSame('Rule 000', $first->json('data.0.name'));
        $this->assertSame('write_audit', $first->json('data.0.actions.0.type'));
        $this->assertSame('demo', $first->json('data.0.conditions.site'));

        $ids = collect($first->json('data'))->pluck('id')->all();
        foreach ([2 => 25, 3 => 25, 4 => 25, 5 => 3] as $page => $size) {
            $response = $this->actingAs($ownerA)
                ->getJson("/tenants/automation-alpha/admin/automations?page={$page}&per_page=25")
                ->assertOk()
                ->assertJsonPath('total', 103)
                ->assertJsonPath('current_page', $page)
                ->assertJsonCount($size, 'data')
                ->assertDontSee('Foreign');
            $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->all());
        }
        $this->assertCount(103, array_unique($ids));

        $this->actingAs($ownerA)
            ->getJson('/tenants/automation-alpha/admin/automations?page=999999&per_page=25')
            ->assertOk()
            ->assertJsonPath('current_page', 5)
            ->assertJsonCount(3, 'data');

        $this->actingAs($ownerA)->getJson('/tenants/automation-alpha/admin/automations')
            ->assertOk()
            ->assertJsonCount(103, 'data')
            ->assertJsonMissingPath('current_page');

        $this->assertSame($before, DB::table('automation_rules')->count());
    }

    public function test_search_only_opt_in_and_literal_case_insensitive_search_are_sql_paged(): void
    {
        [$tenant, $owner] = $this->tenantWithPermissions('automation-search', ['operations.manage']);
        $this->insertRules($tenant, $owner, 26, 'Search Rule');
        $this->insertRules($tenant, $owner, 1, 'Literal%Rule');
        $this->insertRules($tenant, $owner, 1, 'Literal_Rule');
        $this->insertRules($tenant, $owner, 1, 'Literal!Rule');

        $second = $this->actingAs($owner)
            ->getJson('/tenants/automation-search/admin/automations?search=search%20rule&page=2&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 26)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->assertSame('Search Rule 010', $second->json('data.0.name'));

        $this->actingAs($owner)
            ->getJson('/tenants/automation-search/admin/automations?search=search%20rule')
            ->assertOk()
            ->assertJsonPath('total', 26)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data');

        foreach ([
            'search=%25' => 'Literal%Rule 000',
            'search=%5F' => 'Literal_Rule 000',
            'search=%21' => 'Literal!Rule 000',
        ] as $filter => $expected) {
            $this->actingAs($owner)
                ->getJson('/tenants/automation-search/admin/automations?'.$filter.'&page=1&per_page=10')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.name', $expected);
        }

        $this->actingAs($owner)
            ->getJson('/tenants/automation-search/admin/automations?search=notfound&page=9&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');
    }

    public function test_role_permission_cross_tenant_and_input_boundaries_fail_closed(): void
    {
        [, $reader] = $this->tenantWithPermissions('automation-reader', ['tenant.view']);
        [, $owner] = $this->tenantWithPermissions('automation-owner', ['operations.manage']);
        $this->tenantWithPermissions('automation-foreign', ['operations.manage']);

        $this->actingAs($reader)
            ->getJson('/tenants/automation-reader/admin/automations?page=1&per_page=25')
            ->assertForbidden();

        foreach ([
            'page=0', 'page=1000001', 'page=abc', 'per_page=0',
            'per_page=200', 'search%5B%5D=bad&page=1',
        ] as $invalid) {
            $this->actingAs($owner)
                ->getJson('/tenants/automation-owner/admin/automations?'.$invalid)
                ->assertUnprocessable();
        }

        $this->actingAs($owner)
            ->getJson('/tenants/automation-foreign/admin/automations?page=1&per_page=25')
            ->assertNotFound();
    }

    public function test_workspace_uses_server_paging_and_real_size_selector(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/AdminOperationsController.php'));

        $this->assertStringContainsString("route.key === 'automation' ? {", $pages);
        $this->assertStringContainsString('per_page: String(automationPageSize)', $pages);
        $this->assertStringContainsString('setAutomationPageSize(size)', $pages);
        $this->assertStringContainsString("route.key === 'automation' ? automationPageSize", $pages);
        $this->assertStringContainsString('paginatedAutomations(', $controller);
    }

    /** @return array{Tenant, User} */
    private function tenantWithPermissions(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'automation-reader']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }

    private function insertRules(Tenant $tenant, User $actor, int $count, string $prefix = 'Rule'): void
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = [
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $actor->id,
                'name' => sprintf('%s %03d', $prefix, $i),
                'trigger' => 'manual',
                'conditions' => '{"site":"demo"}',
                'actions' => '[{"type":"write_audit"}]',
                'approval_required' => false,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('automation_rules')->insert($records);
    }
}
