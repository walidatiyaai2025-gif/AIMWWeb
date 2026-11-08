<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExecutionCenterRealActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_execution_center_has_real_tenant_pagination_filters_and_no_fake_or_secret_rows(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        [, $foreign] = $this->tenantWithOwner('beta');

        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=1&per_page=25')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('meta.empty_reason', 'no_tenant_executions')
            ->assertJsonCount(0, 'data');

        for ($i = 0; $i < 27; $i++) {
            $this->execution($tenant, $owner->user_id, 'failed', 'report.export', ['api_key' => 'DO_NOT_LEAK'], $i);
        }
        $this->execution(Tenant::query()->withoutGlobalScopes()->findOrFail($foreign->tenant_id), $foreign->user_id, 'queued', 'sync.foreign', ['secret' => 'FOREIGN']);

        $page = $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=2&per_page=25')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('current_page', 2)->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.can_retry', false);
        $this->assertStringNotContainsString('DO_NOT_LEAK', $page->getContent());
        $this->assertStringNotContainsString('FOREIGN', $page->getContent());

        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=1&per_page=10&status=queued')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('meta.empty_reason', 'filter_matched_none');
        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=1&per_page=25&status=failed&type=report.')
            ->assertOk()->assertJsonPath('total', 27);
        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=1&per_page=999')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=0&per_page=25')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        // Existing integration consumers keep their legacy response contract.
        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations')
            ->assertOk()->assertJsonCount(27, 'data');
    }

    public function test_cancel_requires_owner_state_and_is_idempotent_with_one_audit_event(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        [, $foreign] = $this->tenantWithOwner('beta');
        $queued = $this->execution($tenant, $owner->user_id, 'queued', 'sync.fixture');
        $completed = $this->execution($tenant, $owner->user_id, 'completed', 'sync.fixture');

        $url = '/tenants/alpha/admin/operations/'.$queued.'/cancel';
        $this->actingAs($foreign->user)->postJson('/tenants/beta/admin/operations/'.$queued.'/cancel')
            ->assertNotFound();
        $this->actingAs($owner->user)->postJson('/tenants/alpha/admin/operations/'.$completed.'/cancel')
            ->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->actingAs($owner->user)->postJson($url)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->actingAs($owner->user)->postJson($url)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('operation_executions', ['id' => $queued, 'status' => 'cancelled']);
        $this->assertSame(1, DB::table('operation_logs')->where('operation_execution_id', $queued)->count());
    }

    public function test_retry_only_dispatches_supported_owned_work_once_and_rejects_unsupported_types(): void
    {
        Queue::fake();
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        [, $foreign] = $this->tenantWithOwner('beta');

        $report = $this->actingAs($owner->user)
            ->postJson('/tenants/alpha/admin/reports/exports', ['report_type' => 'operations', 'format' => 'csv'])
            ->assertAccepted()->json();
        $reportOperation = (int) DB::table('report_exports')->where('id', $report['id'])->value('operation_execution_id');
        DB::table('operation_executions')->where('id', $reportOperation)->update([
            'status' => 'failed', 'attempts' => 0, 'max_attempts' => 3, 'failure' => 'synthetic fixture failure',
        ]);

        $url = '/tenants/alpha/admin/operations/'.$reportOperation.'/retry';
        $this->actingAs($foreign->user)->postJson('/tenants/beta/admin/operations/'.$reportOperation.'/retry')
            ->assertNotFound();
        $this->actingAs($owner->user)->getJson('/tenants/alpha/admin/operations?page=1&per_page=25')
            ->assertOk()->assertJsonPath('data.0.can_retry', true);
        $this->actingAs($owner->user)->postJson($url)->assertOk()->assertJsonPath('status', 'retrying');
        $this->actingAs($owner->user)->postJson($url)->assertOk()->assertJsonPath('attempts', 1);
        Queue::assertPushed(GenerateReportExport::class, 2);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'operation.retry_requested')->count());

        $unsupported = $this->execution($tenant, $owner->user_id, 'failed', 'integration.unimplemented');
        $this->actingAs($owner->user)->postJson('/tenants/alpha/admin/operations/'.$unsupported.'/retry')
            ->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseHas('operation_executions', [
            'id' => $unsupported, 'status' => 'failed', 'attempts' => 0,
        ]);
    }

    /** @return array{Tenant,TenantMembership} */
    private function tenantWithOwner(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-owner']);
        foreach (['tenant.view', 'operations.manage', 'execution.view', 'reports.manage'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    /** @param array<string, mixed> $payload */
    private function execution(Tenant $tenant, int $actorUserId, string $status, string $type, array $payload = [], int $offset = 0): int
    {
        return (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => $actorUserId,
            'type' => $type,
            'correlation_id' => (string) Str::uuid(),
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
            'safe_to_cancel' => true,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes($offset),
            'updated_at' => now()->subMinutes($offset),
        ]);
    }
}
