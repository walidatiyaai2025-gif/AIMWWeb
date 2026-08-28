<?php

namespace Tests\Feature;

use App\Frontend\ActionContractRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionContractClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_actions_map_uniquely_to_canonical_ledger_rows(): void
    {
        $audit = app(ActionContractRegistry::class)->auditCanonicalMappings();

        $this->assertSame(4, $audit['mapped']);
        $this->assertSame(2, $audit['visible_controls']);
        $this->assertCount(4, array_unique($audit['operation_ids']));
        foreach ($audit['operation_ids'] as $operationId) {
            $this->assertMatchesRegularExpression('/^AIMW-[A-Z]+-[0-9A-F]{10}$/', $operationId);
        }
    }

    public function test_site_bound_action_carries_owned_site_and_semantic_blocker(): void
    {
        $user = User::factory()->create();
        $alphaMembership = $this->tenantMembership($user, 'alpha', ['tenant.view', 'seo.view', 'seo.manage']);
        $alpha = Tenant::query()->withoutGlobalScopes()->findOrFail($alphaMembership->tenant_id);
        $alphaSite = $this->siteFor($alpha, 'Alpha Site');

        $response = $this->actingAs($user)->getJson("/tenants/alpha/context?site={$alphaSite->id}");
        $response->assertOk()->assertJsonPath('active_site.id', $alphaSite->id);

        $action = $response->json('actions')['seo.audit.run'];
        $this->assertSame('AIMW-SEO-FB0F0E9067', $action['operation_id']);
        $this->assertSame($alphaSite->id, $action['site_id']);
        $this->assertSame('seo.manage', $action['permission']);
        $this->assertSame("/api/tenants/alpha/sites/{$alphaSite->id}/seo/audits", $action['endpoint']);
        $this->assertSame('pending_integration', $action['availability']['state']);
        $this->assertSame(
            'Canonical SEO audit execution is approval-required, but the current Laravel endpoint dispatches immediately.',
            $action['availability']['reason'],
        );

        $outsider = User::factory()->create();
        $betaMembership = $this->tenantMembership($outsider, 'beta', ['tenant.view', 'seo.manage']);
        $beta = Tenant::query()->withoutGlobalScopes()->findOrFail($betaMembership->tenant_id);
        $betaSite = $this->siteFor($beta, 'Beta Site');

        // Context ownership is the frontend contract boundary. Do not invoke the
        // known non-terminal SEO route here: current SeoController route binding
        // throws before its ownership check, and that backend defect is evidence.
        $this->actingAs($user)->getJson("/tenants/alpha/context?site={$betaSite->id}")->assertNotFound();
    }

    public function test_wrong_tenant_membership_cannot_be_mutated_and_permission_is_enforced(): void
    {
        $operator = User::factory()->create();
        $alphaMembership = $this->tenantMembership($operator, 'alpha', ['tenant.view', 'users.view', 'members.manage']);
        $target = User::factory()->create();
        $targetMembership = $this->addMembershipToTenant($target, $alphaMembership->tenant_id);

        $foreignUser = User::factory()->create();
        $betaMembership = $this->tenantMembership($foreignUser, 'beta', ['tenant.view']);

        $this->actingAs($operator)
            ->patchJson("/tenants/alpha/admin/members/{$betaMembership->id}", ['status' => 'inactive'])
            ->assertNotFound();

        $limited = User::factory()->create();
        $this->addMembershipToTenant($limited, $alphaMembership->tenant_id, ['tenant.view', 'users.view']);
        $this->actingAs($limited)
            ->patchJson("/tenants/alpha/admin/members/{$targetMembership->id}", ['status' => 'inactive'])
            ->assertForbidden();
    }

    public function test_visible_disable_action_mutates_then_authoritative_read_returns_new_state(): void
    {
        $operator = User::factory()->create();
        $alphaMembership = $this->tenantMembership($operator, 'alpha', ['tenant.view', 'users.view', 'members.manage']);
        $target = User::factory()->create();
        $targetMembership = $this->addMembershipToTenant($target, $alphaMembership->tenant_id);

        $context = $this->actingAs($operator)->getJson('/tenants/alpha/context')->assertOk();
        $action = $context->json('actions')['users.disable'];
        $this->assertSame('AIMW-SYNC-6FCFE15D24', $action['operation_id']);
        $this->assertSame('enabled', $action['availability']['state']);

        $this->actingAs($operator)
            ->patchJson("/tenants/alpha/admin/members/{$targetMembership->id}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->actingAs($operator)
            ->getJson('/tenants/alpha/admin/members')
            ->assertOk()
            ->assertJsonFragment(['id' => $targetMembership->id, 'status' => 'inactive']);
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->addMembershipToTenant($user, $tenant->id, $permissions);
    }

    private function addMembershipToTenant(User $user, int $tenantId, array $permissions = []): TenantMembership
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'role-'.$tenantId.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership;
    }

    private function siteFor(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test']);
        $context->forget();

        return $site;
    }
}
