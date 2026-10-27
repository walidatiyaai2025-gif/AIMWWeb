<?php

namespace Tests\Feature;

use App\AI\Platform\Services\AutomationCenterService;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationCenterClaimDueJobsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-E8F4848B4A';

    public function test_canonical_service_identity_is_bound_to_the_laravel_adapter(): void
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
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('src/AIWordPressManager.Web/Services/AutomationCenterService.cs', $operation['current_source']);
        $this->assertSame('AutomationCenterService', $operation['service']);
        $this->assertSame('ClaimDueJobs', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame(self::OPERATION_ID, AutomationCenterService::CLAIM_DUE_JOBS_OPERATION_ID);
    }

    public function test_claim_due_jobs_claims_only_due_supported_work_for_the_active_tenant(): void
    {
        $at = Carbon::parse('2026-09-08 06:15:00', 'UTC');
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $user = User::factory()->create();

        $dueSync = $this->insertTask($alpha->id, $user->id, 'sync', $at->copy()->subMinutes(10), true, 'Queued');
        $dueSeo = $this->insertTask($alpha->id, $user->id, 'seo_audit', $at->copy()->subMinutes(5), true, null);
        $future = $this->insertTask($alpha->id, $user->id, 'sync', $at->copy()->addMinute(), true, 'Queued');
        $disabled = $this->insertTask($alpha->id, $user->id, 'sync', $at->copy()->subMinute(), false, 'Queued');
        $running = $this->insertTask($alpha->id, $user->id, 'sync', $at->copy()->subMinute(), true, 'Running', $at->copy()->subHour());
        $unsupported = $this->insertTask($alpha->id, $user->id, 'backup_l1', $at->copy()->subMinute(), true, 'Queued');
        $foreign = $this->insertTask($beta->id, $user->id, 'sync', $at->copy()->subMinutes(20), true, 'Queued');

        app(TenantContext::class)->activate($alpha);

        $claimed = app(AutomationCenterService::class)->claimDueJobs($at);

        $this->assertSame([$dueSync, $dueSeo], array_column($claimed, 'id'));
        $this->assertSame(['Running', 'Running'], array_column($claimed, 'last_status'));
        $this->assertNotFoundForForeignTenant($foreign, $claimed);

        foreach ([$dueSync, $dueSeo] as $id) {
            $row = DB::table('scheduled_tasks')->where('id', $id)->first();
            $this->assertSame('Running', $row->last_status);
            $this->assertTrue(Carbon::parse($row->last_run_at)->equalTo($at));
        }

        $this->assertSame('Queued', DB::table('scheduled_tasks')->where('id', $future)->value('last_status'));
        $this->assertSame('Queued', DB::table('scheduled_tasks')->where('id', $disabled)->value('last_status'));
        $this->assertSame('Running', DB::table('scheduled_tasks')->where('id', $running)->value('last_status'));
        $this->assertSame('Queued', DB::table('scheduled_tasks')->where('id', $unsupported)->value('last_status'));
        $this->assertSame('Queued', DB::table('scheduled_tasks')->where('id', $foreign)->value('last_status'));
        $this->assertSame(0, DB::table('operation_executions')->count());
    }

    public function test_claim_due_jobs_does_not_reclaim_running_work(): void
    {
        $at = Carbon::parse('2026-09-08 06:30:00', 'UTC');
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $task = $this->insertTask($tenant->id, $user->id, 'sync', $at->copy()->subMinute(), true, 'Queued');
        app(TenantContext::class)->activate($tenant);

        $service = app(AutomationCenterService::class);
        $first = $service->claimDueJobs($at);
        $second = $service->claimDueJobs($at->copy()->addMinutes(5));

        $this->assertSame([$task], array_column($first, 'id'));
        $this->assertSame([], $second);

        $row = DB::table('scheduled_tasks')->where('id', $task)->first();
        $this->assertSame('Running', $row->last_status);
        $this->assertTrue(Carbon::parse($row->last_run_at)->equalTo($at));
    }

    /** @param array<int, array<string, mixed>> $claimed */
    private function assertNotFoundForForeignTenant(int $foreignTenantTaskId, array $claimed): void
    {
        $this->assertNotContains($foreignTenantTaskId, array_column($claimed, 'id'));
    }

    private function insertTask(
        int $tenantId,
        int $actorUserId,
        string $taskType,
        Carbon $nextRunAt,
        bool $enabled,
        ?string $lastStatus,
        ?Carbon $lastRunAt = null,
    ): int {
        return (int) DB::table('scheduled_tasks')->insertGetId([
            'tenant_id' => $tenantId,
            'created_by_user_id' => $actorUserId,
            'name' => 'task-'.$tenantId.'-'.$taskType.'-'.$nextRunAt->timestamp,
            'task_type' => $taskType,
            'schedule' => '* * * * *',
            'timezone' => 'UTC',
            'enabled' => $enabled,
            'payload' => json_encode([], JSON_THROW_ON_ERROR),
            'retry_policy' => json_encode(['max_attempts' => 1], JSON_THROW_ON_ERROR),
            'next_run_at' => $nextRunAt,
            'last_run_at' => $lastRunAt,
            'last_status' => $lastStatus,
            'created_at' => $nextRunAt->copy()->subDay(),
            'updated_at' => $nextRunAt->copy()->subDay(),
        ]);
    }
}
