<?php

namespace Tests\Feature;

use App\Http\Controllers\AiCenterApprovalStatusController;
use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AiCenterRefreshApprovalStatusTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_canonical_refresh_state_operation_is_bound_to_the_real_read_only_contract(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', 'AIMW-AI-168B406674');

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('/ai-center', $operation['route_screen']);
        $this->assertStringContainsString('RefreshApprovalStatusClicked', $operation['visible_control']);
        $this->assertStringEndsWith('AICenter.razor', $operation['current_source']);
        $this->assertFalse($operation['mutation']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/ai-center/approval-status', 'GET'));
        $this->assertSame(AiCenterApprovalStatusController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_refresh_rereads_only_the_latest_approval_owned_by_the_authenticated_user(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerMembership = $this->membership($owner, $tenant, ['tenant.view', 'ai.use']);
        $otherMembership = $this->membership($other, $tenant, ['tenant.view', 'ai.use']);

        $this->seedApproval($ownerMembership, 'PENDING');
        $latestOwned = $this->seedApproval($ownerMembership, 'APPROVED');
        $foreignUserApproval = $this->seedApproval($otherMembership, 'REJECTED');

        $response = $this->actingAs($owner)->getJson('/api/tenants/alpha/ai-center/approval-status');

        $response->assertOk()
            ->assertJsonPath('data.id', $latestOwned->id)
            ->assertJsonPath('data.status', 'APPROVED');
        $this->assertNotSame($foreignUserApproval->id, $response->json('data.id'));
    }

    public function test_refresh_is_tenant_isolated_and_returns_truthful_empty_state(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $alphaUser = User::factory()->create();
        $betaUser = User::factory()->create();
        $this->membership($alphaUser, $alpha, ['tenant.view', 'ai.use']);
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);
        $this->seedApproval($betaMembership, 'APPROVED');

        $this->actingAs($alphaUser)->getJson('/api/tenants/alpha/ai-center/approval-status')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->actingAs($alphaUser)->getJson('/api/tenants/beta/ai-center/approval-status')
            ->assertNotFound();
    }

    public function test_refresh_requires_ai_use_and_does_not_mutate_persisted_approval(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $allowedUser = User::factory()->create();
        $limitedUser = User::factory()->create();
        $allowedMembership = $this->membership($allowedUser, $tenant, ['tenant.view', 'ai.use']);
        $this->membership($limitedUser, $tenant, ['tenant.view']);
        $approval = $this->seedApproval($allowedMembership, 'PENDING')->fresh();
        $beforeUpdatedAt = $approval->updated_at?->toISOString();
        $beforeCount = Approval::query()->withoutGlobalScopes()->count();

        $this->getJson('/api/tenants/alpha/ai-center/approval-status')->assertUnauthorized();
        $this->actingAs($limitedUser)->getJson('/api/tenants/alpha/ai-center/approval-status')->assertForbidden();
        $this->actingAs($allowedUser)->getJson('/api/tenants/alpha/ai-center/approval-status')
            ->assertOk()
            ->assertJsonPath('data.id', $approval->id)
            ->assertJsonPath('data.status', 'PENDING');

        $fresh = Approval::query()->withoutGlobalScopes()->findOrFail($approval->id);
        $this->assertSame($beforeCount, Approval::query()->withoutGlobalScopes()->count());
        $this->assertSame('PENDING', $fresh->status);
        $this->assertSame($beforeUpdatedAt, $fresh->updated_at?->toISOString());
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'ai-center-refresh-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function seedApproval(TenantMembership $membership, string $status): Approval
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $this->sequence++;

        $site = Site::query()->create([
            'name' => $membership->tenant->name.' AI Site '.$this->sequence,
            'url' => 'https://'.$membership->tenant->slug.'-'.$membership->user_id.'-'.$this->sequence.'.test',
        ]);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 10000 + $this->sequence,
            'slug' => 'ai-center-refresh-'.$this->sequence,
            'title' => 'AI Center refresh candidate '.$this->sequence,
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $membership->user_id,
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'ai_center_refresh_'.$this->sequence,
            'severity' => 'medium',
            'recommendation' => 'Review AI Center proposal',
        ]);
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $membership->user_id,
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
            'status' => 'succeeded',
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $membership->user_id,
            'status' => $status,
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
            'decided_at' => $status === 'PENDING' ? null : now(),
        ]);

        $context->forget();

        return $approval;
    }
}
