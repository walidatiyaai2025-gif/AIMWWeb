<?php

namespace Tests\Feature;

use App\Http\Controllers\ApprovalQueueReadController;
use App\Models\Approval;
use App\Models\Execution;
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

class ApprovalQueueLoadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_operation_is_bound_to_the_real_approval_read_contract(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', 'AIMW-APPR-31A36E339F');

        $this->assertNotNull($operation);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('approvals', $operation['domain']);
        $this->assertSame('/approvals', $operation['route_screen']);
        $this->assertSame('LoadAsync [LoadAsync]', $operation['visible_control']);

        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('AIMW-APPR-31A36E339F', $appSource);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/approvals', 'GET'));
        $this->assertSame(ApprovalQueueReadController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_load_returns_only_active_tenant_approvals_with_execution_state(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'approvals.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'approvals.view']);

        $alphaApproval = $this->seedApproval($alpha, 'APPROVED', 'running');
        $betaApproval = $this->seedApproval($beta, 'PENDING');

        $response = $this->actingAs($user)->getJson('/api/tenants/alpha/approvals');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alphaApproval->id)
            ->assertJsonPath('data.0.status', 'APPROVED')
            ->assertJsonPath('data.0.execution_status', 'running');

        $this->assertNotSame($betaApproval->id, $response->json('data.0.id'));
    }

    public function test_load_rejects_a_foreign_tenant_route(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha-foreign', ['tenant.view', 'approvals.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta-foreign', ['tenant.view', 'approvals.view']);

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/beta-foreign/approvals')
            ->assertNotFound();
    }

    public function test_load_requires_approval_read_permission_and_never_falls_back_to_demo_rows(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited', ['tenant.view']);
        $this->seedApproval($membership, 'PENDING');

        $this->actingAs($user)->getJson('/api/tenants/limited/approvals')->assertForbidden();

        $allowed = User::factory()->create();
        $this->membership($allowed, 'empty', ['tenant.view', 'approvals.view']);
        $this->actingAs($allowed)->getJson('/api/tenants/empty/approvals')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_load_supports_refresh_search_without_cross_tenant_leakage(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-search', ['tenant.view', 'approvals.view']);
        $this->seedApproval($alpha, 'APPROVED');
        $this->seedApproval($alpha, 'PENDING');

        $this->actingAs($user)->getJson('/api/tenants/alpha-search/approvals?search=APPROVED')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'APPROVED');
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "approval-load-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function seedApproval(TenantMembership $membership, string $status, ?string $executionStatus = null): Approval
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $siteSequence = Site::query()->count() + 1;
        $site = Site::query()->create([
            'name' => $membership->tenant->name." Site {$siteSequence}",
            'url' => 'https://'.$membership->tenant->slug."-{$siteSequence}.test",
        ]);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => random_int(1000, 999999),
            'slug' => 'approval-load-'.$site->id,
            'title' => 'Approval load candidate',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $membership->user_id,
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'approval_load_test',
            'severity' => 'medium',
            'recommendation' => 'Review this change',
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

        if ($executionStatus !== null) {
            Execution::query()->create([
                'operation_id' => fake()->uuid(),
                'request_id' => fake()->uuid(),
                'correlation_id' => fake()->uuid(),
                'site_id' => $site->id,
                'approval_id' => $approval->id,
                'actor_user_id' => $membership->user_id,
                'status' => $executionStatus,
            ]);
        }

        $context->forget();

        return $approval;
    }
}
