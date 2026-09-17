<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationCenterSaveIdempotencyLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-BC1C75CE0D';

    public function test_original_create_key_cannot_create_a_duplicate_after_the_job_changes(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');
        $original = $this->payload($site->id);

        $jobId = (int) $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $original,
            ['Idempotency-Key' => 'lifetime-key'],
        )->assertCreated()->json('data.id');

        $this->actingAs($user)->putJson(
            "/api/tenants/alpha/automation-center/jobs/{$jobId}",
            [...$original, 'name' => 'Changed after create', 'expected_version' => 1],
        )->assertOk()->assertJsonPath('data.version', 2);

        $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $original,
            ['Idempotency-Key' => 'lifetime-key'],
        )->assertConflict();

        $this->assertDatabaseCount('automation_center_jobs', 1);
        $this->assertDatabaseCount('automation_center_job_audits', 2);
        $this->assertDatabaseHas('automation_center_jobs', [
            'id' => $jobId,
            'owner_user_id' => $user->id,
            'idempotency_key' => 'lifetime-key',
            'name' => 'Changed after create',
            'version' => 2,
        ]);
    }

    private function payload(int $siteId): array
    {
        return [
            'name' => 'Nightly synchronization',
            'site_id' => $siteId,
            'type' => 'Synchronization',
            'frequency' => 'daily',
            'interval_value' => 1,
            'time_of_day' => '02:30',
            'enabled' => true,
            'retry_count' => 2,
        ];
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'active'],
        );
        $role = Role::query()->create(['name' => 'Role-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'-'.$membership->tenant_id.'.example.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
