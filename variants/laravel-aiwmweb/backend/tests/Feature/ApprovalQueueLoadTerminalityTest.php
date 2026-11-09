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
use Illuminate\Support\Facades\DB;
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

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/approvals', 'GET'));
        $this->assertSame(ApprovalQueueReadController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_frontend_context_advertises_the_real_approval_read_contract(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'approval-contract', ['tenant.view', 'approvals.view']);

        $this->actingAs($user)->getJson('/tenants/approval-contract/context')
            ->assertOk()
            ->assertJsonPath('api.approvals', '/api/tenants/approval-contract/approvals');

        $this->actingAs($user)->getJson('/api/tenants/approval-contract/approvals')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');

        // Advertising this route must not grant access to another tenant.
        $this->actingAs($user)->getJson('/tenants/foreign-tenant/context')
            ->assertNotFound();
        $this->actingAs($user)->getJson('/api/tenants/foreign-tenant/approvals')
            ->assertNotFound();
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

    public function test_approval_queue_uses_real_bounded_pages_and_clamps_stale_page_requests(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'approval-page-alpha', ['tenant.view', 'approvals.view']);
        $beta = $this->membership($user, 'approval-page-beta', ['tenant.view', 'approvals.view']);

        for ($i = 0; $i < 12; $i++) {
            $this->seedApproval($alpha, $i < 7 ? 'PENDING' : 'APPROVED');
        }
        $this->seedApproval($beta, 'PENDING');

        $first = $this->actingAs($user)->getJson('/api/tenants/approval-page-alpha/approvals?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(10, 'data');
        $second = $this->actingAs($user)->getJson('/api/tenants/approval-page-alpha/approvals?page=2&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 12)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data');

        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $secondIds = collect($second->json('data'))->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($firstIds, $secondIds)));

        $this->actingAs($user)->getJson('/api/tenants/approval-page-alpha/approvals?page=99&per_page=10')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data');
        $this->actingAs($user)->getJson('/api/tenants/approval-page-alpha/approvals?page=2&per_page=10&search=PENDING')
            ->assertOk()
            ->assertJsonPath('total', 7)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(7, 'data');

        foreach (['page=0', 'page=-1', 'page=nope', 'page=1000001', 'page%5B%5D=1', 'per_page=500', 'per_page=0'] as $invalid) {
            $this->actingAs($user)->getJson('/api/tenants/approval-page-alpha/approvals?'.$invalid)
                ->assertUnprocessable();
        }
        $this->actingAs($user)->getJson('/api/tenants/approval-page-beta/approvals?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data');
    }

    public function test_stale_approval_bookmark_never_executes_a_large_offset_select(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'approval-safe-offset', ['tenant.view', 'approvals.view']);
        $this->seedApproval($alpha, 'PENDING');

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->actingAs($user)
                ->getJson('/api/tenants/approval-safe-offset/approvals?page=1000000&per_page=10&search=PENDING')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('current_page', 1)
                ->assertJsonCount(1, 'data');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $approvalSelects = 0;
        foreach ($queries as $query) {
            if (preg_match('/\\bfrom\\s+["`]?approvals["`]?/i', $query['query']) !== 1) {
                continue;
            }
            $approvalSelects++;
            // Tenant IDs and search IDs are not offset values.
            if (preg_match('/\\boffset\\s+(\\d+)/i', $query['query'], $matches) === 1) {
                $this->assertLessThanOrEqual(10, (int) $matches[1]);
            } elseif (preg_match('/\\boffset\\s+\\?/i', $query['query']) === 1) {
                $this->assertLessThanOrEqual(10, (int) end($query['bindings']));
            }
        }
        $this->assertGreaterThanOrEqual(2, $approvalSelects);
    }

    public function test_literal_search_escapes_sql_wildcards_and_keeps_exact_totals_and_tenant_scope(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'approval-literal-alpha', ['tenant.view', 'approvals.view']);
        $beta = $this->membership($user, 'approval-literal-beta', ['tenant.view', 'approvals.view']);

        foreach ([
            'Revenue 90% gain',
            'Revenue 90X gain',
            'queue_worker',
            'queueXworker',
            'auth!failed',
            'authXfailed',
        ] as $title) {
            $approval = $this->seedApproval($alpha, 'PENDING');
            $context = app(TenantContext::class);
            $context->activate($alpha->tenant, $alpha);
            $approval->title = $title;
            $approval->save();
            $context->forget();
        }
        $other = $this->seedApproval($beta, 'PENDING');
        $context = app(TenantContext::class);
        $context->activate($beta->tenant, $beta);
        $other->title = 'Revenue 90% foreign';
        $other->save();
        $context->forget();

        $url = '/api/tenants/approval-literal-alpha/approvals';
        foreach ([
            ['90%', 'Revenue 90% gain'],
            ['queue_', 'queue_worker'],
            ['auth!', 'auth!failed'],
        ] as [$search, $expectedTitle]) {
            $this->actingAs($user)
                ->getJson($url.'?per_page=10&page=1&search='.rawurlencode($search))
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('last_page', 1)
                ->assertJsonPath('data.0.title', $expectedTitle)
                ->assertJsonCount(1, 'data');
        }

        // Digit-only search must still support exact approval/suggestion IDs.
        $this->actingAs($user)
            ->getJson($url.'?per_page=10&page=1000000&search=NoMatchingLiteral')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->getJson('/api/tenants/approval-literal-beta/approvals?search='.rawurlencode('90%'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Revenue 90% foreign');
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
