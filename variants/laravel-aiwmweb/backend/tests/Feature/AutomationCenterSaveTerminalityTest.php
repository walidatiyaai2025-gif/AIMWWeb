<?php

namespace Tests\Feature;

use App\Http\Controllers\AutomationCenterJobSaveController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AutomationCenterSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-BC1C75CE0D';

    public function test_route_is_exactly_guarded_and_csrf_session_backed(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/automation-center/jobs', 'POST'));

        $this->assertSame(AutomationCenterJobSaveController::class.'@store', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_guest_permission_and_cross_tenant_requests_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/automation-center/jobs', [])->assertUnauthorized();

        $limited = User::factory()->create(['platform_admin' => true]);
        $this->membership($limited, 'limited', []);
        $this->actingAs($limited)->postJson(
            '/api/tenants/limited/automation-center/jobs',
            $this->payload(1),
            ['Idempotency-Key' => 'limited-1'],
        )->assertForbidden();

        $alpha = User::factory()->create(['platform_admin' => true]);
        $this->membership($alpha, 'alpha', ['operations.manage']);
        $this->membership(User::factory()->create(), 'beta', ['operations.manage']);
        $this->actingAs($alpha)->postJson(
            '/api/tenants/beta/automation-center/jobs',
            $this->payload(1),
            ['Idempotency-Key' => 'cross-tenant-1'],
        )->assertNotFound();
    }

    public function test_create_is_server_owned_authoritatively_persisted_and_idempotent(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');
        $payload = $this->payload($site->id);

        $first = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $payload,
            ['Idempotency-Key' => 'save-1'],
        )->assertCreated()
            ->assertJsonPath('data.site_name', 'Alpha Site')
            ->assertJsonPath('data.type', 'Synchronization')
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.owner_user_id')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.request_hash');

        $jobId = (int) $first->json('data.id');
        $this->assertDatabaseHas('automation_center_jobs', [
            'id' => $jobId,
            'tenant_id' => $membership->tenant_id,
            'owner_user_id' => $user->id,
            'site_id' => $site->id,
            'site_name' => 'Alpha Site',
            'name' => 'Nightly synchronization',
            'version' => 1,
        ]);
        $this->assertDatabaseCount('automation_center_job_audits', 1);
        $this->assertDatabaseCount('scheduled_tasks', 0);
        $this->assertDatabaseCount('automation_rules', 0);

        $second = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $payload,
            ['Idempotency-Key' => 'save-1'],
        )->assertCreated();
        $this->assertSame($jobId, (int) $second->json('data.id'));
        $this->assertDatabaseCount('automation_center_jobs', 1);
        $this->assertDatabaseCount('automation_center_job_audits', 1);

        $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            [...$payload, 'name' => 'Different configuration'],
            ['Idempotency-Key' => 'save-1'],
        )->assertConflict();
        $this->assertDatabaseCount('automation_center_jobs', 1);
        $this->assertDatabaseCount('automation_center_job_audits', 1);
    }

    public function test_foreign_site_and_direct_job_ids_are_indistinguishable_from_missing_ids(): void
    {
        $owner = User::factory()->create(['platform_admin' => true]);
        $alphaMembership = $this->membership($owner, 'alpha', ['operations.manage']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $created = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $this->payload($alphaSite->id),
            ['Idempotency-Key' => 'owner-create'],
        )->assertCreated();
        $jobId = (int) $created->json('data.id');

        $betaUser = User::factory()->create(['platform_admin' => true]);
        $betaMembership = $this->membership($betaUser, 'beta', ['operations.manage']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $foreignSite = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $this->payload($betaSite->id),
            ['Idempotency-Key' => 'foreign-site'],
        );
        $missingSite = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $this->payload(999999),
            ['Idempotency-Key' => 'missing-site'],
        );
        $this->assertSame(404, $foreignSite->status());
        $this->assertSame($foreignSite->status(), $missingSite->status());

        $peer = User::factory()->create(['platform_admin' => true]);
        $this->membership($peer, 'alpha', ['operations.manage']);
        $update = [...$this->payload($alphaSite->id), 'expected_version' => 1];
        $foreignJob = $this->actingAs($peer)->putJson("/api/tenants/alpha/automation-center/jobs/{$jobId}", $update);
        $missingJob = $this->actingAs($peer)->putJson('/api/tenants/alpha/automation-center/jobs/999999', $update);
        $this->assertSame(404, $foreignJob->status());
        $this->assertSame($foreignJob->status(), $missingJob->status());
    }

    public function test_update_retry_is_idempotent_and_stale_conflict_is_rejected(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');
        $jobId = (int) $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            $this->payload($site->id),
            ['Idempotency-Key' => 'update-seed'],
        )->assertCreated()->json('data.id');

        $changed = [...$this->payload($site->id), 'name' => 'Changed once', 'expected_version' => 1];
        $this->actingAs($user)->putJson("/api/tenants/alpha/automation-center/jobs/{$jobId}", $changed)
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('automation_center_job_audits', 2);

        $this->actingAs($user)->putJson("/api/tenants/alpha/automation-center/jobs/{$jobId}", $changed)
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseCount('automation_center_job_audits', 2);

        $this->actingAs($user)->putJson(
            "/api/tenants/alpha/automation-center/jobs/{$jobId}",
            [...$changed, 'name' => 'Conflicting stale write'],
        )->assertConflict();
        $this->assertDatabaseCount('automation_center_job_audits', 2);
    }

    public function test_validation_identity_spoofing_and_entitlements_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($admin, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');

        $this->actingAs($admin)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            [...$this->payload($site->id), 'type' => 'Content Operation'],
            ['Idempotency-Key' => 'bad-type'],
        )->assertUnprocessable();
        $this->actingAs($admin)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            [...$this->payload($site->id), 'tenant_id' => 999, 'owner_user_id' => 999, 'site_name' => 'Spoof', 'api_key' => 'secret'],
            ['Idempotency-Key' => 'spoof'],
        )->assertUnprocessable();
        $this->actingAs($admin)->postJson(
            '/api/tenants/alpha/automation-center/jobs',
            [...$this->payload($site->id), 'retry_count' => 11],
            ['Idempotency-Key' => 'bad-retry'],
        )->assertUnprocessable();

        $nonAdmin = User::factory()->create(['platform_admin' => false]);
        $nonAdminMembership = $this->membership($nonAdmin, 'no-plan', ['operations.manage']);
        $noPlanSite = $this->site($nonAdminMembership, 'No Plan Site');
        $this->actingAs($nonAdmin)->postJson(
            '/api/tenants/no-plan/automation-center/jobs',
            $this->payload($noPlanSite->id),
            ['Idempotency-Key' => 'no-plan-sync'],
        )->assertForbidden()->assertJsonPath('code', 'ENTITLEMENT_DENIED');
        $this->actingAs($nonAdmin)->postJson(
            '/api/tenants/no-plan/automation-center/jobs',
            [...$this->payload($noPlanSite->id), 'type' => 'SEO Audit'],
            ['Idempotency-Key' => 'no-plan-seo'],
        )->assertForbidden()->assertJsonPath('code', 'ENTITLEMENT_DENIED');
        $this->assertDatabaseMissing('automation_center_jobs', ['tenant_id' => $nonAdminMembership->tenant_id]);
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
