<?php

namespace Tests\\Feature;

use App\\Http\\Controllers\\EvidenceReceiptReadController;
use App\\Models\\Approval;
use App\\Models\\EvidenceReceipt;
use App\\Models\\Execution;
use App\\Models\\Permission;
use App\\Models\\Role;
use App\\Models\\SeoAudit;
use App\\Models\\SeoFinding;
use App\\Models\\Site;
use App\\Models\\Suggestion;
use App\\Models\\SyncedContent;
use App\\Models\\Tenant;
use App\\Models\\TenantMembership;
use App\\Models\\User;
use App\\Tenancy\\TenantContext;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Route;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

final class EvidenceReceiptReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_read_route_is_explicit_and_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/evidence', 'GET'));

        $this->assertSame(EvidenceReceiptReadController::class, ltrim($route->getActionName(), '\\\\'));
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

    public function test_empty_tenant_returns_a_real_empty_collection(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'evidence-empty', ['tenant.view', 'execution.view']);

        $this->actingAs($user)->getJson('/api/tenants/evidence-empty/evidence')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
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

    private function receipt(TenantMembership $membership, bool $verified): EvidenceReceipt
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $membership->tenant->name.' Evidence Site',
            'url' => 'https://'.$membership->tenant->slug.'.test',
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
        $operationId = (string) Str::uuid();
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
