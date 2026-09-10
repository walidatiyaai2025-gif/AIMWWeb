<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterServiceAdapter;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ExecutionCenterEnqueueTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-0706ECEF6C';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_execution_center_enqueue_identity_is_bound_to_the_laravel_adapter(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('service:ExecutionCenterService', $operation['route_screen']);
        $this->assertSame('ExecutionCenterService', $operation['service']);
        $this->assertSame('Enqueue', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterServiceAdapter::ENQUEUE_OPERATION_ID);
    }

    public function test_enqueue_registers_one_tracked_job_and_initial_activity_without_fake_progress(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Primary Site');
        app(TenantContext::class)->activate($tenant, $owner);

        $job = app(ExecutionCenterServiceAdapter::class)->enqueue(
            $owner->user_id,
            $siteId,
            '  Nightly sync  ',
            '  sync.full  ',
            '  Primary Site  ',
            0,
            '  idem-001  ',
            '00000000-0000-0000-0000-000000000227',
        );

        $this->assertSame('00000000-0000-0000-0000-000000000227', $job['job_id']);
        $this->assertSame('Nightly sync', $job['title']);
        $this->assertSame('sync.full', $job['type']);
        $this->assertSame('Primary Site', $job['site_name']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0, $job['progress']);
        $this->assertSame(1, $job['total_items']);
        $this->assertSame(0, $job['processed_items']);
        $this->assertSame('Tracked', $job['execution_mode']);
        $this->assertSame('idem-001', $job['idempotency_key']);
        $this->assertNull($job['started_at']);
        $this->assertNull($job['completed_at']);
        $this->assertNull($job['error']);

        $execution = DB::table('operation_executions')->where('id', $job['row_id'])->first();
        $this->assertNotNull($execution);
        $this->assertSame($tenant->id, (int) $execution->tenant_id);
        $this->assertSame($owner->user_id, (int) $execution->requested_by_user_id);
        $this->assertSame('sync.full', $execution->type);
        $this->assertSame('site', $execution->subject_type);
        $this->assertSame((string) $siteId, $execution->subject_id);
        $this->assertSame('queued', $execution->status);
        $this->assertSame(0, (int) $execution->progress);
        $this->assertSame(0, (int) $execution->attempts);
        $this->assertSame(1, (int) $execution->max_attempts);
        $this->assertNull($execution->started_at);
        $this->assertNull($execution->completed_at);
        $this->assertNull($execution->result);
        $this->assertNull($execution->failure);

        $payload = json_decode((string) $execution->payload, true, 512, JSON_THROW_ON_ERROR);
        $expectedPayload = [
            'title' => 'Nightly sync',
            'site_name' => 'Primary Site',
            'total_items' => 1,
            'processed_items' => 0,
            'execution_mode' => 'Tracked',
            'idempotency_key' => 'idem-001',
        ];
        ksort($expectedPayload);
        ksort($payload);
        $this->assertSame($expectedPayload, $payload);

        $logs = DB::table('operation_logs')->where('operation_execution_id', $job['row_id'])->get();
        $this->assertCount(1, $logs);
        $this->assertSame('info', $logs[0]->level);
        $this->assertSame('Registered sync.full execution for Primary Site.', $logs[0]->message);
        $context = json_decode((string) $logs[0]->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('ExecutionCenterService.Enqueue', $context['source']);
        $this->assertDatabaseMissing('operation_logs', [
            'operation_execution_id' => $job['row_id'],
            'level' => 'success',
        ]);
    }

    public function test_enqueue_redacts_credential_assignments_and_generates_correlation_when_omitted(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Secret Site');
        app(TenantContext::class)->activate($tenant, $owner);

        $job = app(ExecutionCenterServiceAdapter::class)->enqueue(
            $owner->user_id,
            $siteId,
            'token=top-secret nightly',
            'report.export',
            'api_key=hidden-value',
            5,
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $job['job_id']);
        $this->assertSame('token=[REDACTED] nightly', $job['title']);
        $this->assertSame('api_key=[REDACTED]', $job['site_name']);
        $this->assertStringNotContainsString('top-secret', json_encode($job, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('hidden-value', json_encode($job, JSON_THROW_ON_ERROR));

        $execution = DB::table('operation_executions')->where('id', $job['row_id'])->first();
        $log = DB::table('operation_logs')->where('operation_execution_id', $job['row_id'])->first();
        $this->assertStringNotContainsString('top-secret', (string) $execution->payload);
        $this->assertStringNotContainsString('hidden-value', (string) $execution->payload);
        $this->assertStringNotContainsString('hidden-value', (string) $log->message);
    }

    public function test_enqueue_fails_closed_for_wrong_owner_permission_and_cross_tenant_site(): void
    {
        [$alpha, $owner] = $this->tenantWithPermission('alpha', true);
        [$beta, $otherOwner] = $this->tenantWithPermission('beta', true);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        app(TenantContext::class)->activate($alpha, $owner);
        try {
            app(ExecutionCenterServiceAdapter::class)->enqueue($otherOwner->user_id, $alphaSite, 'Wrong owner', 'sync.full', 'Alpha', 1);
            $this->fail('A caller must not enqueue work for another owner.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseMissing('operation_executions', [
            'tenant_id' => $alpha->id,
            'requested_by_user_id' => $otherOwner->user_id,
        ]);

        try {
            app(ExecutionCenterServiceAdapter::class)->enqueue($owner->user_id, $betaSite, 'Foreign site', 'sync.full', 'Beta', 1);
            $this->fail('A foreign site must fail closed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseMissing('operation_executions', [
            'tenant_id' => $alpha->id,
            'subject_id' => (string) $betaSite,
        ]);

        app(TenantContext::class)->forget();
        [$gamma, $unprivileged] = $this->tenantWithPermission('gamma', false);
        $gammaSite = $this->site($gamma, 'Gamma Site');
        app(TenantContext::class)->activate($gamma, $unprivileged);
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterServiceAdapter::class)->enqueue($unprivileged->user_id, $gammaSite, 'Denied', 'sync.full', 'Gamma', 1);
    }

    public function test_enqueue_validates_identity_required_fields_and_tenant_context_before_writing(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Alpha Site');
        app(TenantContext::class)->activate($tenant, $owner);

        foreach ([
            [0, $siteId, 'Title', 'sync.full'],
            [$owner->user_id, 0, 'Title', 'sync.full'],
            [$owner->user_id, $siteId, '   ', 'sync.full'],
            [$owner->user_id, $siteId, 'Title', '   '],
        ] as [$ownerId, $candidateSiteId, $title, $type]) {
            try {
                app(ExecutionCenterServiceAdapter::class)->enqueue($ownerId, $candidateSiteId, $title, $type, 'Alpha', 1);
                $this->fail('Invalid enqueue input must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, DB::table('operation_executions')->count());
        $this->assertSame(0, DB::table('operation_logs')->count());

        app(TenantContext::class)->forget();
        $this->expectException(LogicException::class);
        app(ExecutionCenterServiceAdapter::class)->enqueue($owner->user_id, $siteId, 'No context', 'sync.full', 'Alpha', 1);
    }

    /** @return array{0:Tenant,1:TenantMembership} */
    private function tenantWithPermission(string $slug, bool $operationsManage): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role']);
        $permissions = ['tenant.view'];
        if ($operationsManage) {
            $permissions[] = 'operations.manage';
        }
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    private function site(Tenant $tenant, string $name): int
    {
        return (int) DB::table('sites')->insertGetId([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
