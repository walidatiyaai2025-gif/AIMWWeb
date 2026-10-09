<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationReviewConflictsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5887A977D7';

    public function test_source_contract_and_route_bind_exact_review_conflicts_operation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-review-conflicts-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('OnClick="ReviewConflictsAsync"', $source);
        $this->assertStringContainsString('_conflictReview = await SyncService.ReviewConflictsAsync(_selectedSiteId);', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('GlobalSynchronizationReviewConflictsControl', $app);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/conflicts', 'GET'));
        $this->assertSame('App\\Http\\Controllers\\SyncApiController@conflicts', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_conflict_review_is_tenant_scoped_authorized_and_read_only(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha, [], 'no-content-view');

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/conflicts")
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->assertDatabaseCount('content_conflicts', 0);
        $this->assertDatabaseCount('sync_runs', 0);

        $this->actingAs($viewer)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/conflicts")
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/conflicts")
            ->assertNotFound();

        $this->assertDatabaseCount('content_conflicts', 0);
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_guest_review_is_unauthorized(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view']);
        $site = $this->site($alpha, 'Alpha Site');

        $this->getJson("/api/v1/tenants/alpha/sites/{$site->id}/conflicts")
            ->assertUnauthorized();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }

    private function site(Tenant $tenant, string $name): Site
    {
        app(TenantContext::class)->activate($tenant);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
            ]);
        } finally {
            app(TenantContext::class)->forget();
        }
    }
}
