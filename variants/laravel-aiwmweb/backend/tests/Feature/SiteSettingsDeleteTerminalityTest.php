<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteSettingsDeleteController;
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

class SiteSettingsDeleteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-D7D075EF3C';

    public function test_runtime_path_is_explicit_web_auth_tenant_context_and_csrf_rendered(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1/settings', 'DELETE'));

        $this->assertSame('canonical.site.settings.delete', $route->getName());
        $this->assertSame(SiteSettingsDeleteController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id']);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Alpha Site');

        $response = $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings");

        $response->assertOk();
        $response->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);
        $response->assertSee('name="confirmation"', false);
        $response->assertSee('name="_token"', false);
        $response->assertDontSee('name="tenant_id"', false);
        $response->assertDontSee('name="actor_user_id"', false);
        $response->assertDontSee('name="site_id"', false);
    }

    public function test_authentication_and_sites_manage_authorization_fail_closed(): void
    {
        $this->delete('/tenants/alpha/sites/1/settings', ['confirmation' => 'Alpha Site'])
            ->assertRedirect();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited', ['tenant.view', 'sites.view']);
        $site = $this->site($membership->tenant, 'Limited Site');

        $this->actingAs($user)
            ->deleteJson("/tenants/limited/sites/{$site->id}/settings", ['confirmation' => $site->name])
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
    }

    public function test_active_tenant_and_direct_site_id_are_server_scoped_and_foreign_access_is_404(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha->tenant, 'Alpha Site');

        $betaTenant = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $betaSite = $this->site($betaTenant, 'Beta Site');

        $this->actingAs($user)
            ->deleteJson("/tenants/beta/sites/{$alphaSite->id}/settings", ['confirmation' => $alphaSite->name])
            ->assertNotFound();

        $this->actingAs($user)
            ->deleteJson("/tenants/alpha/sites/{$betaSite->id}/settings", ['confirmation' => $betaSite->name])
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id, 'tenant_id' => $alpha->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaTenant->id]);
    }

    public function test_exact_name_confirmation_is_required_before_any_destructive_write(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'confirm', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Exact Site Name');

        $this->actingAs($user)
            ->deleteJson("/tenants/confirm/sites/{$site->id}/settings", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->actingAs($user)
            ->deleteJson("/tenants/confirm/sites/{$site->id}/settings", ['confirmation' => 'exact site name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_running_execution_blocks_delete_without_cancelling_or_mutating_anything(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'busy', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Busy Site');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $approval = $this->approvalForSite($site, $user);
        $execution = Execution::query()->create([
            'operation_id' => fake()->uuid(),
            'request_id' => fake()->uuid(),
            'correlation_id' => fake()->uuid(),
            'site_id' => $site->id,
            'approval_id' => $approval->id,
            'actor_user_id' => $user->id,
            'status' => 'running',
        ]);
        $context->forget();

        $this->actingAs($user)
            ->deleteJson("/tenants/busy/sites/{$site->id}/settings", ['confirmation' => $site->name])
            ->assertConflict();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
        $this->assertDatabaseHas('executions', ['id' => $execution->id, 'status' => 'running', 'cancelled_at' => null]);
    }

    public function test_success_is_truthful_after_authoritative_reread_and_replay_fails_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'owned', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Owned Site');
        $foreignTenant = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignSite = $this->site($foreignTenant, 'Foreign Site');

        $response = $this->actingAs($user)
            ->withSession(['canonical_site_id' => $site->id])
            ->delete("/tenants/owned/sites/{$site->id}/settings", [
                'confirmation' => $site->name,
                'tenant_id' => $foreignTenant->id,
                'actor_user_id' => User::factory()->create()->id,
                'site_id' => $foreignSite->id,
                'secret' => 'must-not-be-echoed',
            ]);

        $response->assertRedirect('/sites');
        $response->assertSessionHas('status', 'Site deleted.');
        $response->assertSessionMissing('canonical_site_id');
        $response->assertDontSee('must-not-be-echoed');
        $this->assertDatabaseMissing('sites', ['id' => $site->id, 'tenant_id' => $membership->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $foreignSite->id, 'tenant_id' => $foreignTenant->id]);

        $this->actingAs($user)
            ->deleteJson("/tenants/owned/sites/{$site->id}/settings", ['confirmation' => $site->name])
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $foreignSite->id, 'tenant_id' => $foreignTenant->id]);
    }

    private function approvalForSite(Site $site, User $user): Approval
    {
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 1,
            'slug' => 'site-settings-delete-active-execution-fixture',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $user->id,
            'status' => 'completed',
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'site_settings_delete_active_execution_fixture',
            'severity' => 'high',
            'recommendation' => 'Keep the execution active for the delete conflict test.',
            'status' => 'open',
        ]);
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $user->id,
            'status' => 'approved',
            'before_state' => ['title' => 'before'],
            'proposed_state' => ['title' => 'after'],
        ]);

        return Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $user->id,
            'status' => 'APPROVED',
            'before_state' => ['title' => 'before'],
            'proposed_state' => ['title' => 'after'],
        ]);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-settings-delete-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.str($name)->slug().'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
