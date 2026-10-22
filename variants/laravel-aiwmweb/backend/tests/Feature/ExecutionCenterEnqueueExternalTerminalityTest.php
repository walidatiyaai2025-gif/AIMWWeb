<?php

namespace Tests\Feature;

use App\Execution\ExecutionCenterExternalEnqueueService;
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

class ExecutionCenterEnqueueExternalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-0B1BC18769';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_execution_center_enqueue_external_identity_is_bound_to_the_service(): void
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
        $this->assertSame('EnqueueExternal', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ExecutionCenterService.cs', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame(self::OPERATION_ID, ExecutionCenterExternalEnqueueService::OPERATION_ID);
    }

    public function test_enqueue_external_registers_one_real_external_job_without_fake_progress(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Primary Site');
        app(TenantContext::class)->activate($tenant, $owner);

        $job = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $siteId,
            '  Provider sync  ',
            '  sync.external  ',
            '  Primary Site  ',
            0,
            '  external-key-001  ',
            '00000000-0000-0000-0000-000000000237',
        );

        $this->assertSame('00000000-0000-0000-0000-000000000237', $job['job_id']);
        $this->assertSame('Provider sync', $job['title']);
        $this->assertSame('sync.external', $job['type']);
        $this->assertSame('Primary Site', $job['site_name']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0, $job['progress']);
        $this->assertSame(1, $job['total_items']);
        $this->assertSame(0, $job['processed_items']);
        $this->assertSame('External', $job['execution_mode']);
        $this->assertSame('external-key-001', $job['idempotency_key']);
        $this->assertNull($job['started_at']);
        $this->assertNull($job['completed_at']);
        $this->assertNull($job['error']);

        $execution = DB::table('operation_executions')->where('id', $job['row_id'])->first();
        $this->assertNotNull($execution);
        $this->assertSame($tenant->id, (int) $execution->tenant_id);
        $this->assertSame($owner->user_id, (int) $execution->requested_by_user_id);
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
        $this->assertSame('External', $payload['execution_mode']);
        $this->assertSame('external-key-001', $payload['idempotency_display']);
        $this->assertSame(hash('sha256', 'external-key-001'), $payload['dedupe_fingerprint']);
        $this->assertSame(0, $payload['processed_items']);

        $logs = DB::table('operation_logs')->where('operation_execution_id', $job['row_id'])->get();
        $this->assertCount(1, $logs);
        $this->assertSame('info', $logs[0]->level);
        $context = json_decode((string) $logs[0]->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::OPERATION_ID, $context['operation_id']);
        $this->assertSame('ExecutionCenterService.EnqueueExternal', $context['source']);
        $this->assertDatabaseMissing('operation_logs', [
            'operation_execution_id' => $job['row_id'],
            'level' => 'success',
        ]);
    }

    public function test_enqueue_external_reuses_owner_idempotency_case_insensitively_without_duplicate_activity(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $firstSite = $this->site($tenant, 'First Site');
        $secondSite = $this->site($tenant, 'Second Site');
        app(TenantContext::class)->activate($tenant, $owner);

        $first = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $firstSite,
            'Original external job',
            'sync.external',
            'First Site',
            9,
            '  EXT-Key-237  ',
            '00000000-0000-0000-0000-000000000238',
        );
        $duplicate = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $secondSite,
            'Replacement values must not win',
            'report.external',
            'Second Site',
            77,
            'ext-key-237',
            '00000000-0000-0000-0000-000000000239',
        );

        $this->assertSame($first['row_id'], $duplicate['row_id']);
        $this->assertSame($first['job_id'], $duplicate['job_id']);
        $this->assertSame($firstSite, $duplicate['site_id']);
        $this->assertSame('Original external job', $duplicate['title']);
        $this->assertSame('sync.external', $duplicate['type']);
        $this->assertSame(9, $duplicate['total_items']);
        $this->assertSame(1, DB::table('operation_executions')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, DB::table('operation_logs')->where('tenant_id', $tenant->id)->count());
    }

    public function test_external_idempotency_does_not_collide_with_tracked_enqueue_or_another_owner(): void
    {
        [$alpha, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($alpha, 'Alpha Site');
        app(TenantContext::class)->activate($alpha, $owner);

        $external = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $siteId,
            'External',
            'sync.external',
            'Alpha Site',
            1,
            'shared-key',
        );
        $tracked = app(ExecutionCenterServiceAdapter::class)->enqueue(
            $owner->user_id,
            $siteId,
            'Tracked',
            'sync.tracked',
            'Alpha Site',
            1,
            'shared-key',
        );

        $this->assertNotSame($external['row_id'], $tracked['row_id']);
        $this->assertSame(2, DB::table('operation_executions')->where('tenant_id', $alpha->id)->count());

        app(TenantContext::class)->forget();
        [$beta, $otherOwner] = $this->tenantWithPermission('beta', true);
        $betaSite = $this->site($beta, 'Beta Site');
        app(TenantContext::class)->activate($beta, $otherOwner);
        $other = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $otherOwner->user_id,
            $betaSite,
            'Other owner',
            'sync.external',
            'Beta Site',
            1,
            'shared-key',
        );

        $this->assertNotSame($external['job_id'], $other['job_id']);
        $this->assertSame(1, DB::table('operation_executions')->where('tenant_id', $beta->id)->count());
    }

    public function test_enqueue_external_redacts_credential_assignments_and_keeps_only_a_fingerprint_for_dedupe(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Secret Site');
        app(TenantContext::class)->activate($tenant, $owner);

        $job = app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $siteId,
            'token=top-secret nightly',
            'sync.external',
            'api_key=hidden-value',
            5,
            'token=idempotency-secret',
        );

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $job['job_id']);
        $encodedJob = json_encode($job, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('top-secret', $encodedJob);
        $this->assertStringNotContainsString('hidden-value', $encodedJob);
        $this->assertStringNotContainsString('idempotency-secret', $encodedJob);

        $execution = DB::table('operation_executions')->where('id', $job['row_id'])->first();
        $log = DB::table('operation_logs')->where('operation_execution_id', $job['row_id'])->first();
        $stored = (string) $execution->payload.' '.(string) $log->message.' '.(string) $log->context;
        $this->assertStringNotContainsString('top-secret', $stored);
        $this->assertStringNotContainsString('hidden-value', $stored);
        $this->assertStringNotContainsString('idempotency-secret', $stored);
        $this->assertStringContainsString(hash('sha256', 'token=idempotency-secret'), (string) $execution->payload);
    }

    public function test_enqueue_external_fails_closed_for_wrong_owner_permission_and_cross_tenant_site(): void
    {
        [$alpha, $owner] = $this->tenantWithPermission('alpha', true);
        [$beta, $otherOwner] = $this->tenantWithPermission('beta', true);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        app(TenantContext::class)->activate($alpha, $owner);
        try {
            app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
                $otherOwner->user_id,
                $alphaSite,
                'Wrong owner',
                'sync.external',
                'Alpha Site',
                1,
                'wrong-owner',
            );
            $this->fail('A caller must not register external work for another owner.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        try {
            app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
                $owner->user_id,
                $betaSite,
                'Foreign site',
                'sync.external',
                'Beta Site',
                1,
                'foreign-site',
            );
            $this->fail('A foreign tenant site must fail closed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, DB::table('operation_executions')->where('tenant_id', $alpha->id)->count());

        app(TenantContext::class)->forget();
        [$gamma, $unprivileged] = $this->tenantWithPermission('gamma', false);
        $gammaSite = $this->site($gamma, 'Gamma Site');
        app(TenantContext::class)->activate($gamma, $unprivileged);
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $unprivileged->user_id,
            $gammaSite,
            'Denied',
            'sync.external',
            'Gamma Site',
            1,
            'denied',
        );
    }

    public function test_enqueue_external_validates_required_input_and_tenant_context_before_writing(): void
    {
        [$tenant, $owner] = $this->tenantWithPermission('alpha', true);
        $siteId = $this->site($tenant, 'Alpha Site');
        app(TenantContext::class)->activate($tenant, $owner);

        foreach ([
            [0, $siteId, 'Title', 'sync.external', 'key'],
            [$owner->user_id, 0, 'Title', 'sync.external', 'key'],
            [$owner->user_id, $siteId, '   ', 'sync.external', 'key'],
            [$owner->user_id, $siteId, 'Title', '   ', 'key'],
            [$owner->user_id, $siteId, 'Title', 'sync.external', '   '],
        ] as [$ownerId, $candidateSiteId, $title, $type, $key]) {
            try {
                app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
                    $ownerId,
                    $candidateSiteId,
                    $title,
                    $type,
                    'Alpha Site',
                    1,
                    $key,
                );
                $this->fail('Invalid EnqueueExternal input must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, DB::table('operation_executions')->count());
        $this->assertSame(0, DB::table('operation_logs')->count());

        app(TenantContext::class)->forget();
        $this->expectException(LogicException::class);
        app(ExecutionCenterExternalEnqueueService::class)->enqueueExternal(
            $owner->user_id,
            $siteId,
            'No context',
            'sync.external',
            'Alpha Site',
            1,
            'no-context',
        );
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
