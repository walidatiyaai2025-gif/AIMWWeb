<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationDetailsReadController;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteOperationDetailsExecutionCenterTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-98F705F888';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_operation_is_the_source_execution_center_visible_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('/module/siteoperationdetails', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationDetails.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(self::OPERATION_ID, SiteOperationDetailsReadController::EXECUTION_CENTER_OPERATION_ID);
    }

    public function test_link_requires_the_correlated_execution_job_and_target_permissions(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'execution.view', 'operations.manage']);
        $correlationId = (string) Str::uuid();
        $operation = $this->recordOperation($membership, $correlationId, 'Alpha Site');

        $this->actingAs($user)
            ->get("/tenants/alpha/site-operations/{$correlationId}")
            ->assertOk()
            ->assertDontSee('Execution Center');

        $unrelatedExecution = $this->recordExecution($membership, $operation->site_id, (string) Str::uuid());

        $this->actingAs($user)
            ->get("/tenants/alpha/site-operations/{$correlationId}")
            ->assertOk()
            ->assertDontSee('Execution Center');

        $execution = $this->recordExecution($membership, $operation->site_id, $correlationId);
        $expectedPath = "/tenants/alpha/module/execution?site={$operation->site_id}";

        $response = $this->actingAs($user)->get("/tenants/alpha/site-operations/{$correlationId}");
        $response->assertOk()
            ->assertSee('Execution Center')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee($expectedPath, false)
            ->assertDontSee((string) $execution->operation_id)
            ->assertDontSee((string) $execution->request_id)
            ->assertDontSee((string) $execution->id)
            ->assertDontSee((string) $unrelatedExecution->operation_id);

        $this->actingAs($user)->get($expectedPath)->assertOk();
    }

    public function test_foreign_tenant_execution_cannot_unlock_the_link(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $alphaUser = User::factory()->create();
        $betaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, $alpha, ['tenant.view', 'execution.view', 'operations.manage']);
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'execution.view', 'operations.manage']);
        $correlationId = (string) Str::uuid();
        $alphaOperation = $this->recordOperation($alphaMembership, $correlationId, 'Alpha Site');
        $betaOperation = $this->recordOperation($betaMembership, $correlationId, 'Beta Site');
        $this->recordExecution($betaMembership, $betaOperation->site_id, $correlationId);

        $this->actingAs($alphaUser)
            ->get("/tenants/alpha/site-operations/{$correlationId}")
            ->assertOk()
            ->assertDontSee('Execution Center');

        $this->recordExecution($alphaMembership, $alphaOperation->site_id, $correlationId);

        $this->actingAs($alphaUser)
            ->get("/tenants/alpha/site-operations/{$correlationId}")
            ->assertOk()
            ->assertSee('Execution Center');
    }

    public function test_page_remains_read_only_and_hides_target_from_user_without_operations_manage(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'execution.view']);
        $correlationId = (string) Str::uuid();
        $operation = $this->recordOperation($membership, $correlationId, 'Alpha Site');
        $this->recordExecution($membership, $operation->site_id, $correlationId);

        $historyBefore = SiteOperationHistory::query()->withoutGlobalScopes()->count();
        $executionsBefore = Execution::query()->withoutGlobalScopes()->count();
        $approvalsBefore = Approval::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get("/tenants/alpha/site-operations/{$correlationId}")
            ->assertOk()
            ->assertDontSee('Execution Center');

        $this->actingAs($user)
            ->get("/tenants/alpha/module/execution?site={$operation->site_id}")
            ->assertForbidden();

        $this->assertSame($historyBefore, SiteOperationHistory::query()->withoutGlobalScopes()->count());
        $this->assertSame($executionsBefore, Execution::query()->withoutGlobalScopes()->count());
        $this->assertSame($approvalsBefore, Approval::query()->withoutGlobalScopes()->count());
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'site-operation-execution-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->load('tenant');
        $context->forget();

        return $membership;
    }

    private function recordOperation(TenantMembership $membership, string $correlationId, string $siteName): SiteOperationHistory
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $siteName,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $siteName)).'.example.test',
        ]);
        $operation = app(SiteOperationHistoryService::class)->record(
            $site->id,
            'content.sync',
            true,
            'Synced authoritative content',
            ['trace_id' => 'trace-42'],
            7,
            $correlationId,
            now()->subSeconds(2),
        );
        $context->forget();

        return $operation;
    }

    private function recordExecution(TenantMembership $membership, int $siteId, string $correlationId): Execution
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $approval = Approval::query()->create([
            'suggestion_id' => null,
            'site_id' => $siteId,
            'site_name' => 'Execution site',
            'actor_user_id' => $membership->user_id,
            'status' => 'APPROVED',
            'source_operation_id' => 'test.site-operation-details',
            'operation_type' => 'content.sync',
            'title' => 'Execution link fixture',
            'actor_label' => 'Test user',
            'risk_level' => 'low',
            'request_key' => (string) Str::uuid(),
            'before_state' => [],
            'proposed_state' => [],
            'decided_at' => now(),
        ]);
        $execution = Execution::query()->create([
            'operation_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'correlation_id' => $correlationId,
            'site_id' => $siteId,
            'approval_id' => $approval->id,
            'actor_user_id' => $membership->user_id,
            'status' => 'completed',
            'attempts' => 1,
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        $context->forget();

        return $execution;
    }
}
