<?php

namespace Tests\Feature;

use App\Jobs\ExecuteApprovedSuggestionJob;
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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SeoRetryFailedVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-49E68B3816';

    private int $nextRemoteId = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_retry_failed_queues_only_approved_failed_tenant_execution_without_inline_wordpress_mutation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-retry', ['tenant.view', 'seo.view', 'seo.write']);
        $site = $this->site($membership, 'Alpha Retry', 'https://alpha-retry.test');
        $retryable = $this->failedExecution($membership, $site, $user, 'APPROVED', ['seo_title' => 'Retry title'], 2);
        $notApproved = $this->failedExecution($membership, $site, $user, 'PENDING', ['seo_title' => 'Blocked title'], 1);
        $emptyProposal = $this->failedExecution($membership, $site, $user, 'APPROVED', [], 4);
        $beforeCounts = [Suggestion::query()->withoutGlobalScopes()->count(), Approval::query()->withoutGlobalScopes()->count(), Execution::query()->withoutGlobalScopes()->count()];

        $response = $this->actingAs($user)->postJson(
            '/api/v1/tenants/alpha-retry/sites/'.$site->id.'/seo/remediations/failed/retry',
            [],
        );

        $response->assertStatus(202)
            ->assertJsonPath('queued', 1)
            ->assertJsonPath('execution_ids.0', $retryable->id)
            ->assertJsonPath('mutated', false);

        $retryable->refresh();
        $notApproved->refresh();
        $emptyProposal->refresh();
        $this->assertSame('queued', $retryable->status);
        $this->assertSame(2, $retryable->attempts, 'Retry preparation must preserve the historical attempt count; the queued job increments it.');
        $this->assertNull($retryable->started_at);
        $this->assertNull($retryable->completed_at);
        $this->assertNull($retryable->failure);
        $this->assertSame('failed', $notApproved->status);
        $this->assertSame('failed', $emptyProposal->status);
        $this->assertSame($beforeCounts, [Suggestion::query()->withoutGlobalScopes()->count(), Approval::query()->withoutGlobalScopes()->count(), Execution::query()->withoutGlobalScopes()->count()]);

        Queue::assertPushed(ExecuteApprovedSuggestionJob::class, function (ExecuteApprovedSuggestionJob $job) use ($membership, $retryable): bool {
            return $job->tenantId === $membership->tenant_id && $job->executionId === $retryable->id;
        });
        Queue::assertPushed(ExecuteApprovedSuggestionJob::class, 1);
    }

    public function test_retry_failed_fails_closed_for_guest_missing_permission_and_foreign_tenant_site(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest Retry', 'slug' => 'guest-retry']);
        $this->post('/api/v1/tenants/'.$guestTenant->slug.'/sites/1/seo/remediations/failed/retry')->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited-retry', ['tenant.view', 'seo.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Retry', 'https://limited-retry.test');
        $this->actingAs($limited)
            ->postJson('/api/v1/tenants/limited-retry/sites/'.$limitedSite->id.'/seo/remediations/failed/retry')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-foreign-retry', ['tenant.view', 'seo.view', 'seo.write']);
        $beta = $this->membership($user, 'beta-foreign-retry', ['tenant.view', 'seo.view', 'seo.write']);
        $alphaSite = $this->site($alpha, 'Alpha Foreign Retry', 'https://alpha-foreign-retry.test');
        $foreignSite = $this->site($beta, 'Foreign Beta Retry', 'https://beta-foreign-retry.test');

        $this->actingAs($user)
            ->postJson('/api/v1/tenants/alpha-foreign-retry/sites/'.$alphaSite->id.'/seo/remediations/failed/retry')
            ->assertStatus(202);
        $this->actingAs($user)
            ->postJson('/api/v1/tenants/alpha-foreign-retry/sites/'.$foreignSite->id.'/seo/remediations/failed/retry')
            ->assertNotFound();
    }

    public function test_canonical_retry_failed_control_and_backend_contract_are_wired_to_the_same_operation(): void
    {
        $frontend = file_get_contents(resource_path('js/seo-visible-controls.tsx'));
        $routes = file_get_contents(base_path('routes/api.php'));
        $service = file_get_contents(app_path('Services/SeoRemediationClosureService.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-testid="seo-retry-failed"', $frontend);
        $this->assertStringContainsString('disabled={busy || failedProposalCount === 0}', $frontend);
        $this->assertStringContainsString("Route::post('/failed/retry'", $routes);
        $this->assertStringContainsString("->where('status', 'failed')", $service);
        $this->assertStringContainsString('$approval->status !== \'APPROVED\'', $service);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'config-retry', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'Config Retry', 'https://config-retry.test');
        $this->actingAs($user)->get('/tenants/config-retry/sites/'.$site->id.'/seo')
            ->assertOk()
            ->assertViewHas('config', fn (array $config): bool => ($config['urls']['retry_failed'] ?? null) === '/api/v1/tenants/config-retry/sites/'.$site->id.'/seo/remediations/failed/retry');
    }

    private function failedExecution(
        TenantMembership $membership,
        Site $site,
        User $user,
        string $approvalStatus,
        array $proposedState,
        int $attempts,
    ): Execution {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $remoteId = ++$this->nextRemoteId;
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => $remoteId,
            'slug' => 'retry-'.$remoteId,
            'title' => 'Retry '.$remoteId,
            'content' => 'Persisted content',
            'excerpt' => '',
            'headings' => [],
            'taxonomy' => [],
            'media' => [],
            'seo_title' => 'Before title',
            'seo_description' => 'Before description',
        ]);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $user->id, 'status' => 'succeeded']);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'retry-'.$remoteId,
            'severity' => 'high',
            'field' => 'seo_title',
            'recommendation' => 'Retry the approved SEO title update.',
            'suggested_value' => $proposedState['seo_title'] ?? null,
        ]);
        $before = ['seo_title' => 'Before title'];
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $user->id,
            'status' => 'ready',
            'before_state' => $before,
            'proposed_state' => $proposedState,
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $user->id,
            'status' => $approvalStatus,
            'before_state' => $before,
            'proposed_state' => $proposedState,
            'decided_at' => $approvalStatus === 'APPROVED' ? now() : null,
        ]);
        $execution = Execution::query()->create([
            'operation_id' => fake()->uuid(),
            'request_id' => fake()->uuid(),
            'correlation_id' => fake()->uuid(),
            'site_id' => $site->id,
            'approval_id' => $approval->id,
            'actor_user_id' => $user->id,
            'status' => 'failed',
            'attempts' => $attempts,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'failure' => 'Previous provider or verification failure.',
        ]);
        $context->forget();

        return $execution;
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-retry-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => $url, 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
