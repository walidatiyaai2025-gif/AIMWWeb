<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoController;
use App\Http\Controllers\SeoRemediationClosureController;
use App\Jobs\RunSeoAuditJob;
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
use ReflectionMethod;
use Tests\TestCase;

final class SeoTenantRouteBindingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tenant_scoped_seo_action_consumes_the_tenant_route_parameter_first(): void
    {
        $actions = [
            SeoController::class => [
                'audits', 'startAudit', 'findings', 'metadata', 'provider',
                'prepare', 'prepareBulk', 'aiProposal', 'executeBulk', 'retry',
            ],
            SeoRemediationClosureController::class => [
                'proposals', 'history', 'retryFailed', 'undo',
            ],
        ];

        foreach ($actions as $controller => $methods) {
            foreach ($methods as $method) {
                $parameters = (new ReflectionMethod($controller, $method))->getParameters();
                $this->assertSame('tenant', $parameters[0]->getName(), "$controller::$method must consume {tenant} before {site}.");
                $this->assertSame('site', $parameters[1]->getName(), "$controller::$method must bind {site} second.");
            }
        }
    }

    public function test_authorized_empty_seo_audits_and_remediation_reads_return_json_not_server_error(): void
    {
        [$user, $membership] = $this->member('wordpress', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'NOB');

        $this->actingAs($user)
            ->getJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/wordpress/sites/{$site->id}/seo/remediations/proposals")
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/wordpress/sites/{$site->id}/seo/remediations/history")
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_seo_findings_read_uses_site_id_and_audit_id_in_the_correct_positions(): void
    {
        [$user, $membership] = $this->member('wordpress', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'NOB');

        app(TenantContext::class)->activate($membership->tenant, $membership);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 123,
            'title' => 'NOB article',
            'slug' => 'nob-article',
            'content' => 'Article text',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $user->id,
            'status' => 'succeeded',
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'missing-description',
            'severity' => 'high',
            'field' => 'seo_description',
            'recommendation' => 'Write a description',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits/{$audit->id}/findings")
            ->assertOk()
            ->assertJsonPath('0.id', $finding->id)
            ->assertJsonPath('0.code', 'missing-description');
    }

    public function test_foreign_site_and_missing_permission_are_denied_before_seo_reads(): void
    {
        [$user, $ownMembership] = $this->member('wordpress', ['tenant.view', 'seo.view']);
        [$otherUser, $otherMembership] = $this->member('other', ['tenant.view', 'seo.view']);
        $foreignSite = $this->site($otherMembership, 'Other Site');
        $site = $this->site($ownMembership, 'NOB');
        [$limitedUser] = $this->member('wordpress', ['seo.view']);

        $this->actingAs($user)
            ->getJson("/api/tenants/wordpress/sites/{$foreignSite->id}/seo/audits")
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson("/api/v1/tenants/wordpress/sites/{$foreignSite->id}/seo/remediations/proposals")
            ->assertNotFound();
        $this->actingAs($limitedUser)
            ->getJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits")
            ->assertForbidden();

        $this->assertNotSame($user->id, $otherUser->id);
    }

    public function test_run_full_audit_preserves_the_seo_manage_gate_and_queue_behavior(): void
    {
        Queue::fake();
        [$viewer, $viewerMembership] = $this->member('wordpress', ['tenant.view', 'seo.view']);
        $site = $this->site($viewerMembership, 'NOB');

        $this->actingAs($viewer)
            ->postJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits", [])
            ->assertForbidden();

        [$manager] = $this->member('wordpress', ['tenant.view', 'seo.view', 'seo.manage']);
        $this->actingAs($manager)
            ->postJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits", [])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(RunSeoAuditJob::class, 1);
    }

    public function test_malformed_site_identifiers_never_alias_a_real_site_or_queue_an_audit(): void
    {
        Queue::fake();
        [$manager, $membership] = $this->member('wordpress', ['tenant.view', 'seo.view', 'seo.manage']);
        $site = $this->site($membership, 'NOB');

        foreach (["{$site->id}invalid", 'not-a-site', '0'] as $malformedSite) {
            $this->actingAs($manager)
                ->postJson("/api/tenants/wordpress/sites/{$malformedSite}/seo/audits", [])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('seo_audits', 0);
        Queue::assertNotPushed(RunSeoAuditJob::class);

        $this->actingAs($manager)
            ->postJson("/api/tenants/wordpress/sites/{$site->id}/seo/audits", [])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(RunSeoAuditJob::class, 1);
    }

    public function test_bulk_seo_failures_return_safe_per_item_messages_without_internal_exception_text(): void
    {
        [$manager, $membership] = $this->member('wordpress', [
            'tenant.view', 'seo.view', 'seo.write', 'executions.manage',
        ]);
        $site = $this->site($membership, 'NOB');

        $prepare = $this->actingAs($manager)->postJson(
            "/api/tenants/wordpress/sites/{$site->id}/seo/remediations/bulk",
            ['items' => [['finding_id' => 987654321, 'changes' => ['seo_title' => 'Test']]]],
        );
        $prepare->assertStatus(207)
            ->assertJsonPath('prepared', [])
            ->assertJsonPath('failed.0.finding_id', 987654321)
            ->assertJsonPath('failed.0.error', 'Item not found or unavailable.');
        $this->assertStringNotContainsString('App\\Models', $prepare->getContent());

        $execute = $this->actingAs($manager)->postJson(
            "/api/tenants/wordpress/sites/{$site->id}/seo/executions/bulk",
            ['approval_ids' => [987654321]],
        );
        $execute->assertStatus(207)
            ->assertJsonPath('queued', [])
            ->assertJsonPath('failed.0.approval_id', 987654321)
            ->assertJsonPath('failed.0.error', 'Item not found or unavailable.');
        $this->assertStringNotContainsString('App\\Models', $execute->getContent());
        $this->assertDatabaseCount('executions', 0);
    }

    public function test_bulk_execution_does_not_reveal_foreign_site_approval_status(): void
    {
        Queue::fake();
        [$manager, $membership] = $this->member('wordpress', [
            'tenant.view', 'seo.view', 'seo.write', 'executions.manage',
        ]);
        $ownSite = $this->site($membership, 'NOB');
        $otherSite = $this->site($membership, 'Other WordPress Site');

        app(TenantContext::class)->activate($membership->tenant, $membership);
        $foreignApprovals = [];
        foreach (['PENDING', 'APPROVED'] as $status) {
            $suggestion = Suggestion::query()->create([
                'site_id' => $otherSite->id,
                'actor_user_id' => $manager->id,
                'status' => 'awaiting_approval',
                'before_state' => [],
                'proposed_state' => ['seo_title' => 'Private SEO change'],
            ]);
            $foreignApprovals[] = Approval::query()->create([
                'suggestion_id' => $suggestion->id,
                'actor_user_id' => $manager->id,
                'status' => $status,
                'before_state' => [],
                'proposed_state' => ['seo_title' => 'Private SEO change'],
            ])->id;
        }
        app(TenantContext::class)->forget();

        $response = $this->actingAs($manager)->postJson(
            "/api/tenants/wordpress/sites/{$ownSite->id}/seo/executions/bulk",
            ['approval_ids' => $foreignApprovals],
        );

        $response->assertStatus(207)
            ->assertJsonPath('queued', [])
            ->assertJsonCount(2, 'failed')
            ->assertJsonPath('failed.0.error', 'Item not found or unavailable.')
            ->assertJsonPath('failed.1.error', 'Item not found or unavailable.');
        $this->assertSame($foreignApprovals[0], $response->json('failed.0.approval_id'));
        $this->assertSame($foreignApprovals[1], $response->json('failed.1.approval_id'));
        $this->assertDatabaseCount('executions', 0);
        Queue::assertNotPushed(ExecuteApprovedSuggestionJob::class);
    }

    /** @return array{User,TenantMembership} */
    private function member(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$user, $membership->fresh('tenant')];
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://example.test', 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
