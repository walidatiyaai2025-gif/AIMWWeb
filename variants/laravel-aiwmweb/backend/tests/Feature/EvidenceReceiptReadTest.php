<?php

namespace Tests\Feature;

use App\Http\Controllers\EvidenceReceiptReadController;
use App\Models\Approval;
use App\Models\EvidenceReceipt;
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
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceReceiptReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_read_route_is_explicit_and_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/evidence', 'GET'));

        $this->assertSame(EvidenceReceiptReadController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_read_returns_only_safe_receipt_metadata_from_the_active_tenant(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'evidence-alpha', ['tenant.view', 'execution.view']);
        $beta = $this->membership($user, 'evidence-beta', ['tenant.view', 'execution.view']);
        $alphaReceipt = $this->receipt($alpha, true);
        $betaReceipt = $this->receipt($beta, true);

        $response = $this->actingAs($user)->getJson('/api/tenants/evidence-alpha/evidence');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alphaReceipt->id)
            ->assertJsonPath('data.0.site_id', $alphaReceipt->site_id)
            ->assertJsonPath('data.0.execution_id', $alphaReceipt->execution_id)
            ->assertJsonPath('data.0.verified', true)
            ->assertJsonPath('data.0.failure_present', false)
            ->assertJsonMissingPath('data.0.before_state')
            ->assertJsonMissingPath('data.0.proposed_state')
            ->assertJsonMissingPath('data.0.actual_after_state')
            ->assertJsonMissingPath('data.0.failure');

        $this->assertNotSame($betaReceipt->id, $response->json('data.0.id'));
    }

    public function test_evidence_read_requires_execution_permission_and_supports_site_and_verification_filters(): void
    {
        $user = User::factory()->create();
        $limited = $this->membership($user, 'evidence-limited', ['tenant.view']);
        $this->receipt($limited, true);
        $this->actingAs($user)->getJson('/api/tenants/evidence-limited/evidence')->assertForbidden();

        $allowed = User::factory()->create();
        $membership = $this->membership($allowed, 'evidence-filtered', ['tenant.view', 'execution.view']);
        $verified = $this->receipt($membership, true);
        $unverified = $this->receipt($membership, false);

        $this->actingAs($allowed)
            ->getJson('/api/tenants/evidence-filtered/evidence?site_id='.$verified->site_id.'&verified=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $verified->id);

        $this->actingAs($allowed)
            ->getJson('/api/tenants/evidence-filtered/evidence?verified=0')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $unverified->id);
    }

    public function test_tenant_context_advertises_the_real_evidence_endpoint(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'evidence-context', ['tenant.view', 'execution.view']);

        $this->actingAs($user)->getJson('/tenants/evidence-context/context')
            ->assertOk()
            ->assertJsonPath('api.evidence', '/api/tenants/evidence-context/evidence');
    }

    public function test_empty_tenant_returns_a_real_empty_collection(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'evidence-empty', ['tenant.view', 'execution.view']);

        $this->actingAs($user)->getJson('/api/tenants/evidence-empty/evidence')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_evidence_pages_have_real_totals_stable_ids_and_tenant_safe_clamping(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'evidence-paging-alpha', ['tenant.view', 'execution.view']);
        $beta = $this->membership($user, 'evidence-paging-beta', ['tenant.view', 'execution.view']);

        for ($i = 0; $i < 11; $i++) {
            $this->receipt($alpha, $i < 6);
        }
        $this->receipt($beta, true);

        $first = $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-alpha/evidence?page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 11)
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2)
            ->assertJsonCount(10, 'data');
        $second = $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-alpha/evidence?page=2&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');
        $this->assertSame([], array_values(array_intersect(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        )));

        $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-alpha/evidence?page=99&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(1, 'data');
        $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-alpha/evidence?page=2&per_page=10&verified=1')
            ->assertOk()->assertJsonPath('total', 6)
            ->assertJsonPath('current_page', 1)->assertJsonCount(6, 'data');

        foreach (['page=0', 'page=nope', 'per_page=101', 'per_page=0'] as $invalid) {
            $this->actingAs($user)
                ->getJson('/api/tenants/evidence-paging-alpha/evidence?'.$invalid)
                ->assertUnprocessable();
        }

        $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-beta/evidence?page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');
        $this->actingAs($user)
            ->getJson('/api/tenants/evidence-paging-alpha/evidence?page=1&per_page=10')
            ->assertJsonMissingPath('data.0.before_state')
            ->assertJsonMissingPath('data.0.actual_after_state')
            ->assertJsonMissingPath('data.0.failure');
    }

    public function test_literal_identifier_search_page_bounds_and_filtered_totals_are_safe(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'evidence-literal-alpha', ['tenant.view', 'execution.view']);
        $beta = $this->membership($user, 'evidence-literal-beta', ['tenant.view', 'execution.view']);

        // Percent and underscore are literal identifier characters, not SQL
        // wildcard operators. Keep the evidence immutable after creation.
        $ids = [
            'audit%9', 'auditX9',
            'queue_7', 'queueX7',
            'risk!flag', 'riskXflag',
        ];
        $receipts = [];
        foreach ($ids as $operationId) {
            $receipts[] = $this->receipt($alpha, true, $operationId);
        }
        $foreign = $this->receipt($beta, true, 'audit%9-foreign');

        $url = '/api/tenants/evidence-literal-alpha/evidence';
        foreach ([
            ['audit%9', 'audit%9'],
            ['queue_7', 'queue_7'],
            ['risk!flag', 'risk!flag'],
        ] as [$needle, $expected]) {
            $this->actingAs($user)
                ->getJson($url.'?page=1&per_page=10&search='.rawurlencode($needle))
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('last_page', 1)
                ->assertJsonPath('data.0.operation_id', $expected)
                ->assertJsonCount(1, 'data')
                ->assertJsonMissingPath('data.0.before_state')
                ->assertJsonMissingPath('data.0.proposed_state')
                ->assertJsonMissingPath('data.0.actual_after_state')
                ->assertJsonMissingPath('data.0.failure');
        }

        // Ordinary search still matches both a literal and a similar string.
        $this->actingAs($user)
            ->getJson($url.'?search=audit&per_page=10')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'data');

        // Authoritative SQL page membership and last-page recovery never use
        // a first-page slice or a giant OFFSET before clamping.
        foreach ([[1, 2], [2, 2], [3, 2], [1000000, 2]] as [$page, $count]) {
            $this->actingAs($user)
                ->getJson($url."?page={$page}&per_page=2")
                ->assertOk()
                ->assertJsonPath('total', 6)
                ->assertJsonPath('current_page', min($page, 3))
                ->assertJsonPath('last_page', 3)
                ->assertJsonCount($count, 'data');
        }
        $this->actingAs($user)
            ->getJson($url.'?page=1000000&search=nonexistent')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->getJson($url.'?site_id='.$receipts[0]->site_id.'&verified=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $receipts[0]->id);

        foreach (['page=0', 'page=-1', 'page=nope', 'page=1000001', 'page%5B%5D=2', 'per_page=101', 'per_page=0'] as $invalid) {
            $this->actingAs($user)->getJson($url.'?'.$invalid)->assertUnprocessable();
        }
        $this->actingAs($user)
            ->getJson('/api/tenants/evidence-literal-beta/evidence?search='.rawurlencode('audit%9'))
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $foreign->id);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "evidence-read-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function receipt(TenantMembership $membership, bool $verified, ?string $operationId = null): EvidenceReceipt
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $membership->tenant->name.' Evidence Site',
            'url' => 'https://'.$membership->tenant->slug.'-'.Str::uuid().'.test',
        ]);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => random_int(1000, 999999),
            'slug' => 'evidence-'.$site->id,
            'title' => 'Evidence test content',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $membership->user_id,
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'evidence_test',
            'severity' => 'medium',
            'recommendation' => 'Review evidence test',
        ]);
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $membership->user_id,
            'status' => 'succeeded',
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $membership->user_id,
            'status' => 'APPROVED',
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
            'decided_at' => now(),
        ]);
        $operationId ??= (string) Str::uuid();
        $requestId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $execution = Execution::query()->create([
            'operation_id' => $operationId,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'site_id' => $site->id,
            'approval_id' => $approval->id,
            'actor_user_id' => $membership->user_id,
            'status' => 'completed',
        ]);
        $receipt = EvidenceReceipt::query()->create([
            'site_id' => $site->id,
            'execution_id' => $execution->id,
            'actor_user_id' => $membership->user_id,
            'operation_id' => $operationId,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'before_state' => ['private' => 'before'],
            'proposed_state' => ['private' => 'proposed'],
            'actual_after_state' => $verified ? ['private' => 'after'] : null,
            'verified' => $verified,
            'failure' => $verified ? null : 'Private failure detail',
        ]);

        $context->forget();

        return $receipt;
    }
}
