<?php

namespace Tests\Feature;

use App\AI\Platform\Services\ApprovalWorkflowService;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ApprovalWorkflowRecordExecutionFailedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-B3BDED59F1';

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
        $this->assertSame('src/AIWordPressManager.Web/Services/ApprovalWorkflowService.cs', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame(self::OPERATION_ID, ApprovalWorkflowService::RECORD_EXECUTION_FAILED_OPERATION_ID);
    }

    public function test_record_execution_failed_marks_only_the_owned_approval_and_redacts_logged_error(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['approvals.manage']);
        app(TenantContext::class)->activate($tenant, $membership);

        $approvalId = $this->insertApproval($tenant->id, $user->id, 1001, 'APPROVED');
        $executionRowId = $this->insertExecution($tenant->id, $user->id, $approvalId, 2001, 'running');
        $executionId = (string) Str::uuid();

        Log::shouldReceive('error')->once()->with(
            'Approved change execution failed.',
            Mockery::on(function (array $context) use ($approvalId, $executionId): bool {
                return $context['canonical_operation'] === self::OPERATION_ID
                    && $context['approval_id'] === $approvalId
                    && $context['execution_id'] === $executionId
                    && str_contains((string) $context['error'], 'token=[REDACTED]')
                    && ! str_contains((string) $context['error'], 'super-secret');
            }),
        );

        $result = app(ApprovalWorkflowService::class)->recordExecutionFailed(
            $approvalId,
            $executionId,
            'provider timeout token=super-secret',
        );

        $this->assertSame('FAILED', $result->status);
        $this->assertDatabaseHas('approvals', ['id' => $approvalId, 'tenant_id' => $tenant->id, 'status' => 'FAILED']);
        $this->assertDatabaseHas('executions', [
            'id' => $executionRowId,
            'tenant_id' => $tenant->id,
            'status' => 'running',
            'failure' => null,
        ]);
    }

    public function test_foreign_tenant_approval_fails_closed_with_model_not_found_exception(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $alpha, ['approvals.manage']);
        app(TenantContext::class)->activate($alpha, $membership);
        $foreignApprovalId = $this->insertApproval($beta->id, $user->id, 1002, 'APPROVED');

        $this->expectException(ModelNotFoundException::class);
        app(ApprovalWorkflowService::class)->recordExecutionFailed(
            $foreignApprovalId,
            (string) Str::uuid(),
            'foreign failure',
        );
    }

    public function test_missing_manage_permission_throws_authorization_exception_and_preserves_state(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['approvals.view']);
        app(TenantContext::class)->activate($tenant, $membership);
        $approvalId = $this->insertApproval($tenant->id, $user->id, 1003, 'APPROVED');

        try {
            app(ApprovalWorkflowService::class)->recordExecutionFailed(
                $approvalId,
                (string) Str::uuid(),
                'should not be recorded',
            );
            $this->fail('Expected AuthorizationException (403-equivalent authorization failure).');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('approvals', ['id' => $approvalId, 'status' => 'APPROVED']);
        }
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'approval-failure-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->load('tenant');
        $context->forget();

        return $membership;
    }

    private function insertApproval(int $tenantId, int $actorUserId, int $suggestionId, string $status): int
    {
        Schema::disableForeignKeyConstraints();
        try {
            return (int) DB::table('approvals')->insertGetId([
                'tenant_id' => $tenantId,
                'suggestion_id' => $suggestionId,
                'actor_user_id' => $actorUserId,
                'status' => $status,
                'before_state' => json_encode(['title' => 'before'], JSON_THROW_ON_ERROR),
                'proposed_state' => json_encode(['title' => 'after'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function insertExecution(int $tenantId, int $actorUserId, int $approvalId, int $siteId, string $status): int
    {
        Schema::disableForeignKeyConstraints();
        try {
            return (int) DB::table('executions')->insertGetId([
                'operation_id' => (string) Str::uuid(),
                'request_id' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'approval_id' => $approvalId,
                'actor_user_id' => $actorUserId,
                'status' => $status,
                'attempts' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
