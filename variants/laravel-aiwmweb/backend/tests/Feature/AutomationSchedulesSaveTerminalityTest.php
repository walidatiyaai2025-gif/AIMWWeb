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

final class AutomationSchedulesSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-B6BE029CB8';

    public function test_schedule_save_route_is_canonical_session_tenant_and_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/automation-schedules/jobs/save', 'POST'));

        $this->assertSame(AutomationCenterJobSaveController::class.'@save', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_create_and_edit_reconcile_authoritative_owned_schedule_state(): void
    {
        $user = User::factory()->create(['platform_admin' => true]);
        $membership = $this->membership($user, 'alpha', ['operations.manage']);
        $site = $this->site($membership);

        $created = $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/jobs/save',
            $this->payload($site->id),
            ['Idempotency-Key' => 'schedule-save-1'],
        )->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.name', 'Nightly synchronization')
            ->assertJsonPath('data.version', 1);

        $id = (int) $created->json('data.id');

        $this->actingAs($user)->postJson(
            '/api/tenants/alpha/automation-schedules/jobs/save',
            [...$this->payload($site->id), 'id' => $id, 'expected_version' => 1, 'name' => 'Morning synchronization'],
            ['Idempotency-Key' => 'ignored-on-update'],
        )->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.name', 'Morning synchronization')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('automation_center_jobs', [
            'id' => $id,
            'tenant_id' => $membership->tenant_id,
            'owner_user_id' => $user->id,
            'site_id' => $site->id,
            'name' => 'Morning synchronization',
            'version' => 2,
        ]);
        $this->assertDatabaseCount('automation_center_job_audits', 2);
    }

    public function test_guest_permission_foreign_site_and_caller_owned_fields_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/automation-schedules/jobs/save', [])->assertUnauthorized();

        $limited = User::factory()->create(['platform_admin' => true]);
        $limitedMembership = $this->membership($limited, 'limited', []);
        $limitedSite = $this->site($limitedMembership);
        $this->actingAs($limited)->postJson(
            '/api/tenants/limited/automation-schedules/jobs/save',
            $this->payload($limitedSite->id),
            ['Idempotency-Key' => 'limited'],
        )->assertForbidden();

        $owner = User::factory()->create(['platform_admin' => true]);
        $alpha = $this->membership($owner, 'alpha', ['operations.manage']);
        $alphaSite = $this->site($alpha);
        $other = User::factory()->create(['platform_admin' => true]);
        $beta = $this->membership($other, 'beta', ['operations.manage']);
        $betaSite = $this->site($beta);

        $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-schedules/jobs/save',
            $this->payload($betaSite->id),
            ['Idempotency-Key' => 'foreign-site'],
        )->assertNotFound();

        $this->actingAs($owner)->postJson(
            '/api/tenants/alpha/automation-schedules/jobs/save',
            [...$this->payload($alphaSite->id), 'tenant_id' => 999, 'owner_user_id' => 999, 'site_name' => 'Spoof'],
            ['Idempotency-Key' => 'spoof'],
        )->assertUnprocessable();
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
        $membership = TenantMembership::query()->firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
        $role = Role::query()->create(['name' => 'Role-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        try {
            return Site::query()->create([
                'name' => ucfirst($membership->tenant->slug).' Site',
                'url' => 'https://'.$membership->tenant->slug.'.example.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
