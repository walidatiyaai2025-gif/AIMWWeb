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

final class SchedulesPagination1608Test extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_rows_are_tenant_scoped_counted_and_paged_without_mutating_tasks(): void
    {
        [$alpha, $owner] = $this->tenantWithPermissions('schedules-alpha', ['operations.manage']);
        [$beta, $otherOwner] = $this->tenantWithPermissions('schedules-beta', ['operations.manage']);
        $this->insertSchedules($alpha, $owner, 101);
        $this->insertSchedules($beta, $otherOwner, 3, 'Foreign');

        $before = DB::table('scheduled_tasks')->count();
        $first = $this->actingAs($owner)
            ->getJson('/tenants/schedules-alpha/admin/schedules?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 101)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 5)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('Foreign');

        $ids = collect($first->json('data'))->pluck('id')->all();
        foreach ([2 => 25, 3 => 25, 4 => 25, 5 => 1] as $page => $size) {
            $response = $this->actingAs($owner)
                ->getJson("/tenants/schedules-alpha/admin/schedules?page={$page}&per_page=25")
                ->assertOk()->assertJsonPath('total', 101)
                ->assertJsonPath('current_page', $page)
                ->assertJsonCount($size, 'data')
                ->assertDontSee('Foreign');
            $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->all());
        }

        $this->assertCount(101, array_unique($ids));
        $this->actingAs($owner)
            ->getJson('/tenants/schedules-alpha/admin/schedules?page=999999&per_page=25')
            ->assertOk()->assertJsonPath('current_page', 5)->assertJsonCount(1, 'data');

        $this->actingAs($owner)->getJson('/tenants/schedules-alpha/admin/schedules')
            ->assertOk()->assertJsonCount(101, 'data')->assertJsonMissingPath('current_page');

        $this->assertSame($before, DB::table('scheduled_tasks')->count());
    }

    public function test_schedule_search_is_literal_case_insensitive_and_stable(): void
    {
        [$tenant, $owner] = $this->tenantWithPermissions('schedules-search', ['operations.manage']);
        $this->insertSchedules($tenant, $owner, 27, 'Search Task');
        $this->insertSchedules($tenant, $owner, 1, 'Literal%Task');
        $this->insertSchedules($tenant, $owner, 1, 'Literal_Task');
        $this->insertSchedules($tenant, $owner, 1, 'Literal!Task');

        $second = $this->actingAs($owner)
            ->getJson('/tenants/schedules-search/admin/schedules?search=search%20task&page=2&per_page=10')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');
        $this->assertSame('Search Task 010', $second->json('data.0.name'));

        foreach ([
            'search=%25' => 'Literal%Task 000',
            'search=%5F' => 'Literal_Task 000',
            'search=%21' => 'Literal!Task 000',
        ] as $filter => $expected) {
            $this->actingAs($owner)
                ->getJson('/tenants/schedules-search/admin/schedules?'.$filter.'&page=1&per_page=10')
                ->assertOk()->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.name', $expected);
        }

        $this->actingAs($owner)
            ->getJson('/tenants/schedules-search/admin/schedules?search=doesnotexist&page=9&per_page=10')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');
    }

    public function test_invalid_paging_or_unauthorized_tenant_access_fails_closed(): void
    {
        [, $reader] = $this->tenantWithPermissions('schedules-reader', ['tenant.view']);
        [, $owner] = $this->tenantWithPermissions('schedules-owner', ['operations.manage']);
        $this->tenantWithPermissions('schedules-foreign', ['operations.manage']);

        $this->actingAs($reader)
            ->getJson('/tenants/schedules-reader/admin/schedules?page=1&per_page=25')
            ->assertForbidden();

        foreach ([
            'page=0', 'page=1000001', 'page=abc', 'per_page=0',
            'per_page=200', 'search%5B%5D=unsafe',
        ] as $invalid) {
            $this->actingAs($owner)
                ->getJson('/tenants/schedules-owner/admin/schedules?'.$invalid)
                ->assertUnprocessable();
        }

        $this->actingAs($owner)
            ->getJson('/tenants/schedules-foreign/admin/schedules?page=1&per_page=25')
            ->assertNotFound();
    }

    public function test_schedules_workspace_uses_authoritative_server_pages(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/AdminOperationsController.php'));

        $this->assertStringContainsString("route.key === 'schedules' ? {", $pages);
        $this->assertStringContainsString('per_page: String(schedulesPageSize)', $pages);
        $this->assertStringContainsString('setSchedulesPageSize(size)', $pages);
        $this->assertStringContainsString("route.key === 'schedules' ? schedulesPageSize", $pages);
        $this->assertStringContainsString('paginatedSchedules(', $controller);
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
        $role = Role::query()->create(['name' => 'schedule-reader']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }

    private function insertSchedules(Tenant $tenant, User $actor, int $count, string $prefix = 'Task'): void
    {
        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $records[] = [
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $actor->id,
                'name' => sprintf('%s %03d', $prefix, $i),
                'task_type' => 'sync',
                'schedule' => 'hourly',
                'timezone' => 'UTC',
                'enabled' => true,
                'payload' => '{}',
                'retry_policy' => '{}',
                'next_run_at' => now()->addHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('scheduled_tasks')->insert($records);
    }
}
