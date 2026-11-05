<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoController;
use App\Http\Controllers\SeoRemediationClosureController;
use App\Jobs\RunSeoAuditJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
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
