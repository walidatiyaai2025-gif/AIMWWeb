<?php

namespace Tests\Feature;

use App\Http\Controllers\AutomationSchedulesSaveController;
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

final class AutomationSchedulesSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B6BE029CB8';

    public function test_exact_saveasync_route_is_session_csrf_tenant_and_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/automation-schedules/save', 'POST'));

        $this->assertSame(AutomationSchedulesSaveController::class.'@save', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());

        $contracts = config('frontend_actions');
        $this->assertIsArray($contracts);
        $contract = $contracts['schedules.save'] ?? null;
        $this->assertIsArray($contract);
        $this->assertSame(self::OPERATION_ID, $contract['operation_id']);
        $this->assertSame('/module/schedules | /automation-schedules', $contract['canonical']['route_screen']);
        $this->assertSame('SaveAsync [SaveAsync]', $contract['canonical']['visible_control']);
        $this->assertSame('/api/tenants/{tenant}/automation-schedules/save', $contract['endpoint']);
        $this->assertSame('high', $contract['risk']);
        $this->assertTrue($contract['idempotency_required']);
    }

    public function test_create_maps_source_save_to_owned_persistence_idempotently_without_execution_side_effects(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');
        $payload = $this->payload($site->id);

        $first = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $payload,
            ['Idempotency-Key' => 'schedules-save-create-1'],
        )->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'created')
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
            'frequency' => 'daily',
            'interval_value' => 1,
            'time_of_day' => '02:30',
            'enabled' => true,
            'retry_count' => 2,
            'version' => 1,
        ]);
        $this->assertDatabaseCount('automation_center_job_audits', 1);
        $this->assertDatabaseCount('scheduled_tasks', 0);
        $this->assertDatabaseCount('automation_rules', 0);

        $second = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $payload,
            ['Idempotency-Key' => 'schedules-save-create-1'],
        )->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'created');

        $this->assertSame($jobId, (int) $second->json('data.id'));
        $this->assertDatabaseCount('automation_center_jobs', 1);
        $this->assertDatabaseCount('automation_center_job_audits', 1);

        $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            [...$payload, 'name' => 'Changed under same key'],
            ['Idempotency-Key' => 'schedules-save-create-1'],
        )->assertConflict();

        $this->assertDatabaseCount('automation_center_jobs', 1);
        $this->assertDatabaseCount('automation_center_job_audits', 1);
    }

    public function test_edit_maps_source_model_id_to_owned_job_with_optimistic_retry_semantics(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');

        $created = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $this->payload($site->id),
            ['Idempotency-Key' => 'schedules-edit-seed'],
        )->assertCreated();

        $jobId = (int) $created->json('data.id');
        $changed = [
            ...$this->payload($site->id),
            'job' => $jobId,
            'expected_version' => 1,
            'name' => 'Weekly SEO audit',
            'type' => 'Synchronization',
            'frequency' => 'weekly',
            'interval_value' => 2,
            'time_of_day' => '08:00',
            'retry_count' => 3,
        ];

        $this->actingAs($user)->postJson('/api/tenants/alpha/automation-schedules/save', $changed)
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'updated')
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.name', 'Weekly SEO audit');

        $this->assertDatabaseCount('automation_center_job_audits', 2);

        $this->actingAs($user)->postJson('/api/tenants/alpha/automation-schedules/save', $changed)
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseCount('automation_center_job_audits', 2);

        $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            [...$changed, 'name' => 'Conflicting stale edit'],
        )->assertConflict();

        $this->assertDatabaseCount('automation_center_job_audits', 2);
    }

    public function test_guest_permission_cross_tenant_foreign_site_and_foreign_job_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/automation-schedules/save', [])->assertUnauthorized();

        $limited = User::factory()->create(['platform_admin' => true]);
        $limitedMembership = $this->membership($limited, 'limited', []);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->postJson(
            '/api/tenants/limited/automation-schedules/save',
            $this->payload($limitedSite->id),
            ['Idempotency-Key' => 'limited-save'],
        )->assertForbidden();

        $owner = User::factory()->create(['platform_admin' => true]);
        $alphaMembership = $this->membership($owner, 'alpha', ['operations.manage']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create(['platform_admin' => true]);
        $betaMembership = $this->membership($betaUser, 'beta', ['operations.manage']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->actingAs($owner)->postJson(
            '/api/tenants/beta/automation-schedules/save',
            $this->payload($betaSite->id),
            ['Idempotency-Key' => 'cross-tenant-save'],
        )->assertNotFound();

        $foreignSite = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $this->payload($betaSite->id),
            ['Idempotency-Key' => 'foreign-site-save'],
        );
        $missingSite = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $this->payload(999999),
            ['Idempotency-Key' => 'missing-site-save'],
        );
        $this->assertSame(404, $foreignSite->status());
        $this->assertSame($foreignSite->status(), $missingSite->status());

        $owned = $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            $this->payload($alphaSite->id),
            ['Idempotency-Key' => 'owner-job-save'],
        )->assertCreated();
        $jobId = (int) $owned->json('data.id');

        $peer = User::factory()->create(['platform_admin' => true]);
        $this->membership($peer, 'alpha', ['operations.manage']);
        $edit = [
            ...$this->payload($alphaSite->id),
            'job' => $jobId,
            'expected_version' => 1,
            'name' => 'Peer edit attempt',
        ];
        $foreignJob = $this->actingAs($peer)->postJson('/api/tenants/alpha/automation-schedules/save', $edit);
        $missingJob = $this->actingAs($peer)->postJson(
            '/api/tenants/alpha/automation-schedules/save',
            [...$edit, 'job' => 999999],
        );
        $this->assertSame(404, $foreignJob->status());
        $this->assertSame($foreignJob->status(), $missingJob->status());
    }

    public function test_mode_validation_identity_spoofing_ranges_and_entitlements_fail_closed(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($admin, 'alpha', ['operations.manage']);
        $site = $this->site($membership, 'Alpha Site');
        $url = '/api/tenants/alpha/automation-schedules/save';

        $this->actingAs($admin)->postJson($url, $this->payload($site->id))
            ->assertUnprocessable();

        $this->actingAs($admin)->postJson(
            $url,
            [...$this->payload($site->id), 'expected_version' => 1],
            ['Idempotency-Key' => 'create-with-version'],
        )->assertUnprocessable();

        $this->actingAs($admin)->postJson(
            $url,
            [...$this->payload($site->id), 'job' => 123],
        )->assertUnprocessable();

        foreach ([
            ['type' => 'Content Operation'],
            ['frequency' => 'secondly'],
            ['interval_value' => 366],
            ['time_of_day' => '25:90'],
            ['retry_count' => 11],
        ] as $invalid) {
            $this->actingAs($admin)->postJson(
                $url,
                [...$this->payload($site->id), ...$invalid],
                ['Idempotency-Key' => 'invalid-'.md5(json_encode($invalid))],
            )->assertUnprocessable();
        }

        $this->actingAs($admin)->postJson(
            $url,
            [
                ...$this->payload($site->id),
                'tenant_id' => 999,
                'owner_user_id' => 999,
                'site_name' => 'Spoofed',
                'api_key' => 'secret',
            ],
            ['Idempotency-Key' => 'spoof-save'],
        )->assertUnprocessable();

        $nonAdmin = User::factory()->create(['platform_admin' => false]);
        $noPlanMembership = $this->membership($nonAdmin, 'no-plan', ['operations.manage']);
        $noPlanSite = $this->site($noPlanMembership, 'No Plan Site');

        $this->actingAs($nonAdmin)->postJson(
            '/api/tenants/no-plan/automation-schedules/save',
            $this->payload($noPlanSite->id),
            ['Idempotency-Key' => 'no-plan-sync'],
        )->assertForbidden()->assertJsonPath('code', 'ENTITLEMENT_DENIED');

        $this->actingAs($nonAdmin)->postJson(
            '/api/tenants/no-plan/automation-schedules/save',
            [...$this->payload($noPlanSite->id), 'type' => 'SEO Audit'],
            ['Idempotency-Key' => 'no-plan-seo'],
        )->assertForbidden()->assertJsonPath('code', 'ENTITLEMENT_DENIED');

        $this->assertDatabaseMissing('automation_center_jobs', ['tenant_id' => $noPlanMembership->tenant_id]);
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
        $role = Role::query()->create(['name' => 'Schedules-'.$slug.'-'.$user->id]);
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
