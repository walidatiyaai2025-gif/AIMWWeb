<?php

namespace Tests\Feature;

use App\Automation\BulkStatusExecutionService;
use App\Automation\BulkTrashExecutionService;
use App\Automation\ExecutionCenterService;
use App\Automation\ExecutionCenterUserCommandService;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomationPhaseServiceTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_IDS = [
        'AIMW-AUTO-FE8B6EAC62',
        'AIMW-AUTO-2B8A1146F8',
        'AIMW-AUTO-FF4812A204',
        'AIMW-AUTO-E57D5E6134',
        'AIMW-AUTO-730076001E',
        'AIMW-AUTO-4C1DD607BB',
    ];

    public function test_execution_center_pause_and_resume_are_real_tenant_scoped_transitions(): void
    {
        [$tenant, $user] = $this->membership('tenant-a', ['operations.manage']);
        $jobId = (string) Str::uuid();
        $rowId = $this->execution($tenant->id, $user->id, $jobId, 'running');

        $service = app(ExecutionCenterService::class);
        $this->assertTrue($service->pause($jobId));
        $this->assertSame('paused', DB::table('operation_executions')->where('id', $rowId)->value('status'));
        $this->assertTrue($service->resume($jobId));
        $this->assertSame('running', DB::table('operation_executions')->where('id', $rowId)->value('status'));

        $this->assertDatabaseHas('operation_logs', ['tenant_id' => $tenant->id, 'correlation_id' => $jobId]);
        $this->assertSame(ExecutionCenterService::PAUSE_OPERATION_ID, self::OPERATION_IDS[2]);
        $this->assertSame(ExecutionCenterService::RESUME_OPERATION_ID, self::OPERATION_IDS[3]);
    }

    public function test_execution_center_user_commands_require_permission_and_owner(): void
    {
        [$tenant, $user] = $this->membership('tenant-command', ['operations.manage']);
        $jobId = (string) Str::uuid();
        $this->execution($tenant->id, $user->id, $jobId, 'running');

        $commands = app(ExecutionCenterUserCommandService::class);
        $this->assertTrue($commands->pause($jobId));
        $this->assertTrue($commands->resume($jobId));
        $this->assertSame(ExecutionCenterUserCommandService::PAUSE_OPERATION_ID, self::OPERATION_IDS[4]);
        $this->assertSame(ExecutionCenterUserCommandService::RESUME_OPERATION_ID, self::OPERATION_IDS[5]);

        [$restricted] = $this->membership('tenant-restricted', ['execution.view']);
        app(TenantContext::class)->activate($restricted, TenantMembership::query()->where('user_id', '!=', 0)->latest('id')->first());
        $this->expectException(AuthorizationException::class);
        app(ExecutionCenterUserCommandService::class)->pause((string) Str::uuid());
    }

    public function test_cross_tenant_execution_ids_fail_closed_as_not_found(): void
    {
        [$tenantA] = $this->membership('tenant-a-cross', ['operations.manage']);
        [$tenantB, $userB] = $this->membership('tenant-b-cross', ['operations.manage']);
        $foreignJobId = (string) Str::uuid();
        $this->execution($tenantB->id, $userB->id, $foreignJobId, 'running');

        $membershipA = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        app(TenantContext::class)->activate($tenantA, $membershipA);

        $this->expectException(ModelNotFoundException::class);
        app(ExecutionCenterService::class)->pause($foreignJobId);
    }

    public function test_bulk_status_and_trash_reject_foreign_tenant_content(): void
    {
        [$tenantA] = $this->membership('tenant-content-a', ['content.edit']);
        [$tenantB] = $this->membership('tenant-content-b', ['content.edit']);
        DB::table('content_items')->insert([
            'tenant_id' => $tenantB->id,
            'site_id' => 44,
            'remote_id' => 9001,
            'type' => 'post',
            'status' => 'publish',
            'sticky' => false,
            'stale' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $membershipA = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantA->id)->firstOrFail();
        app(TenantContext::class)->activate($tenantA, $membershipA);
        $target = [['content_type' => 'post', 'wordpress_id' => 9001]];

        try {
            app(BulkStatusExecutionService::class)->runAsync(44, $target, 'draft');
            $this->fail('Foreign tenant content must fail closed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->expectException(ModelNotFoundException::class);
        app(BulkTrashExecutionService::class)->runAsync(44, $target);
    }

    public function test_service_operation_ids_remain_exact(): void
    {
        $this->assertSame('AIMW-AUTO-FE8B6EAC62', BulkStatusExecutionService::OPERATION_ID);
        $this->assertSame('AIMW-AUTO-2B8A1146F8', BulkTrashExecutionService::OPERATION_ID);
        $this->assertCount(6, self::OPERATION_IDS);
    }

    /** @return array{Tenant, User} */
    private function membership(string $slug, array $permissions): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "automation-phase-{$slug}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->activate($tenant, $membership);

        return [$tenant, $user];
    }

    private function execution(int $tenantId, int $userId, string $correlationId, string $status): int
    {
        return (int) DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenantId,
            'requested_by_user_id' => $userId,
            'type' => 'automation.phase.test',
            'subject_type' => 'site',
            'subject_id' => '1',
            'correlation_id' => $correlationId,
            'status' => $status,
            'progress' => 25,
            'attempts' => 0,
            'max_attempts' => 1,
            'safe_to_cancel' => true,
            'payload' => '{}',
            'result' => null,
            'failure' => null,
            'started_at' => now(),
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
