<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteManagementController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SitesDeleteControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-BE4B8C3822';

    public function test_exact_canonical_operation_is_the_pending_sites_confirm_delete_control(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Sites.razor', $operation['current_source']);
        $this->assertStringContainsString('Confirm', $operation['visible_control']);
        $this->assertTrue((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_runtime_route_is_authenticated_tenant_context_and_web_csrf_protected(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7', 'DELETE'));
        $this->assertSame(SiteManagementController::class.'@destroy', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
    }

    public function test_guest_and_missing_permission_fail_closed_without_persistence_change(): void
    {
        $owner = User::factory()->create();
        $membership = $this->membership($owner, 'alpha-denied', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Denied Site');
        $this->deleteJson('/api/tenants/alpha-denied/sites/'.$site->id)->assertUnauthorized();
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
        $this->actingAs($owner)->deleteJson('/api/tenants/alpha-denied/sites/'.$site->id)->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
    }

    public function test_authorized_delete_is_persisted_verified_and_replay_safe(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-delete', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($alpha, 'Alpha Site');
        $response = $this->actingAs($user)->deleteJson('/api/tenants/alpha-delete/sites/'.$site->id);
        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertDatabaseMissing('sites', ['id' => $site->id, 'tenant_id' => $alpha->tenant_id]);
        $this->actingAs($user)->deleteJson('/api/tenants/alpha-delete/sites/'.$site->id)->assertNotFound();
    }

    public function test_wrong_tenant_foreign_id_and_invalid_id_fail_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-owner', ['tenant.view', 'sites.view', 'sites.manage']);
        $beta = $this->membership($user, 'beta-owner', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');
        $this->actingAs($user)->deleteJson('/api/tenants/beta-owner/sites/'.$alphaSite->id)->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/tenants/alpha-owner/sites/'.$betaSite->id)->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/tenants/alpha-owner/sites/not-a-number')->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/tenants/alpha-owner/sites/0')->assertNotFound();
        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id]);
    }

    public function test_queued_or_running_execution_blocks_delete_and_preserves_site(): void
    {
        foreach (['queued', 'running'] as $status) {
            $user = User::factory()->create();
            $membership = $this->membership($user, 'execution-'.$status, ['tenant.view', 'sites.view', 'sites.manage']);
            $site = $this->site($membership, ucfirst($status).' Site');
            $this->executionFixture($membership, $site, $user, $status);
            $this->actingAs($user)->deleteJson('/api/tenants/'.$membership->tenant->slug.'/sites/'.$site->id)->assertConflict();
            $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
        }
    }

    public function test_completed_execution_does_not_create_false_active_blocker(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'execution-completed', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Completed Site');
        $this->executionFixture($membership, $site, $user, 'completed');
        $this->actingAs($user)->deleteJson('/api/tenants/execution-completed/sites/'.$site->id)->assertNoContent();
        $this->assertDatabaseMissing('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
    }

    public function test_production_sites_workspace_binds_exact_operation_and_authoritative_reread_contract(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/sites-delete-control.tsx'));
        $this->assertStringContainsString("route.key === 'sites'", $app);
        $this->assertStringContainsString('<SitesDeleteControl context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("method: 'DELETE'", $control);
        $this->assertStringContainsString('await query.refetch()', $control);
        $this->assertStringContainsString('authoritative Sites reread still contains the site', $control);
        $this->assertStringNotContainsString('site-details-delete-control', $app);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-delete-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://example.test/'.strtolower(str_replace(' ', '-', $name)), 'status' => 'active']);
        $context->forget();
        return $site;
    }

    private function executionFixture(TenantMembership $membership, Site $site, User $user, string $status): void
    {
        $tenantId = (int) $membership->tenant_id;
        $now = now();
        $syncedContentId = DB::table('synced_contents')->insertGetId(['tenant_id' => $tenantId, 'site_id' => $site->id, 'resource_type' => 'post', 'remote_id' => random_int(1000, 999999), 'slug' => 'delete-guard-'.Str::lower(Str::random(8)), 'created_at' => $now, 'updated_at' => $now]);
        $auditId = DB::table('seo_audits')->insertGetId(['tenant_id' => $tenantId, 'site_id' => $site->id, 'actor_user_id' => $user->id, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        $findingId = DB::table('seo_findings')->insertGetId(['tenant_id' => $tenantId, 'seo_audit_id' => $auditId, 'synced_content_id' => $syncedContentId, 'code' => 'delete-guard', 'severity' => 'warning', 'recommendation' => 'Fixture only', 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        $suggestionId = DB::table('suggestions')->insertGetId(['tenant_id' => $tenantId, 'site_id' => $site->id, 'seo_finding_id' => $findingId, 'synced_content_id' => $syncedContentId, 'actor_user_id' => $user->id, 'status' => 'completed', 'before_state' => json_encode(['title' => 'before'], JSON_THROW_ON_ERROR), 'proposed_state' => json_encode(['title' => 'after'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $approvalId = DB::table('approvals')->insertGetId(['tenant_id' => $tenantId, 'suggestion_id' => $suggestionId, 'actor_user_id' => $user->id, 'status' => 'APPROVED', 'before_state' => json_encode(['title' => 'before'], JSON_THROW_ON_ERROR), 'proposed_state' => json_encode(['title' => 'after'], JSON_THROW_ON_ERROR), 'decided_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('executions')->insert(['operation_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'site_id' => $site->id, 'approval_id' => $approvalId, 'actor_user_id' => $user->id, 'status' => $status, 'attempts' => $status === 'completed' ? 1 : 0, 'started_at' => $status === 'running' || $status === 'completed' ? $now : null, 'completed_at' => $status === 'completed' ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
    }
}
