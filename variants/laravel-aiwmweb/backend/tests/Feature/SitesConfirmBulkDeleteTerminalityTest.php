<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteManagementController;
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

class SitesConfirmBulkDeleteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-337E4FF969';

    public function test_canonical_operation_is_the_critical_sites_confirm_bulk_delete_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('critical', $operation['risk']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertStringContainsString('ConfirmBulkDeleteAsync', (string) $operation['visible_control']);

        $frontend = file_get_contents(resource_path('js/sites-bulk-delete-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-canonical-operation={OPERATION_ID}', $frontend);
        $this->assertStringContainsString('MAX_SITES = 100', $frontend);
        $this->assertStringContainsString("method: 'DELETE'", $frontend);
        $this->assertStringContainsString('mutateThenReconcile', $frontend);
    }

    public function test_bulk_delete_route_is_explicit_guarded_and_bound_to_the_real_controller(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'DELETE'));

        $this->assertSame('canonical.api.sites.bulk-delete', $route->getName());
        $this->assertSame(SiteManagementController::class.'@bulkDestroy', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation']);
    }

    public function test_missing_manage_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'limited', ['tenant.view', 'sites.view']);

        $this->actingAs($user)
            ->deleteJson('/api/tenants/limited/sites', ['ids' => [1]])
            ->assertForbidden();
    }

    public function test_mixed_tenant_selection_fails_not_found_before_any_owned_site_is_deleted(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha->tenant, 'Alpha Site');

        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($user)
            ->deleteJson('/api/tenants/alpha/sites', ['ids' => [$alphaSite->id, $betaSite->id]])
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id, 'tenant_id' => $alpha->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $beta->id]);
    }

    public function test_active_execution_blocks_the_entire_batch_before_any_site_is_deleted(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'busy', ['tenant.view', 'sites.view', 'sites.manage']);
        $first = $this->site($membership->tenant, 'First Site');
        $second = $this->site($membership->tenant, 'Second Site');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $approval = $this->approvalForSite($second, $user);
        Execution::query()->create([
            'operation_id' => fake()->uuid(),
            'request_id' => fake()->uuid(),
            'correlation_id' => fake()->uuid(),
            'site_id' => $second->id,
            'approval_id' => $approval->id,
            'actor_user_id' => $user->id,
            'status' => 'running',
        ]);
        $context->forget();

        $this->actingAs($user)
            ->deleteJson('/api/tenants/busy/sites', ['ids' => [$first->id, $second->id]])
            ->assertConflict();

        $this->assertDatabaseHas('sites', ['id' => $first->id]);
        $this->assertDatabaseHas('sites', ['id' => $second->id]);
    }

    public function test_valid_owned_selection_deletes_atomically_and_authoritative_reread_is_empty(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'clean', ['tenant.view', 'sites.view', 'sites.manage']);
        $first = $this->site($membership->tenant, 'First Site');
        $second = $this->site($membership->tenant, 'Second Site');

        $this->actingAs($user)
            ->deleteJson('/api/tenants/clean/sites', ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('ids.0', $first->id)
            ->assertJsonPath('ids.1', $second->id);

        $this->assertDatabaseMissing('sites', ['id' => $first->id]);
        $this->assertDatabaseMissing('sites', ['id' => $second->id]);
        $this->actingAs($user)->getJson('/api/tenants/clean/sites')->assertOk()->assertExactJson([]);
    }

    public function test_bulk_delete_rejects_more_than_one_hundred_ids(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'limit', ['tenant.view', 'sites.view', 'sites.manage']);

        $this->actingAs($user)
            ->deleteJson('/api/tenants/limit/sites', ['ids' => range(1, 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    private function approvalForSite(Site $site, User $user): Approval
    {
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 1,
            'slug' => 'bulk-delete-active-execution-fixture',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $user->id,
            'status' => 'completed',
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'bulk_delete_active_execution_fixture',
            'severity' => 'high',
            'recommendation' => 'Keep the execution active for the bulk-delete conflict test.',
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
        $role = Role::query()->create(['name' => "sites-bulk-{$slug}-{$user->id}"]);
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
