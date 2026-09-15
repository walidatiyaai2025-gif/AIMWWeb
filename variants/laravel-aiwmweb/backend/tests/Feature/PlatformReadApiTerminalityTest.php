<?php

namespace Tests\Feature;

use App\Http\Controllers\PlatformReadController;
use App\Models\ContentItem;
use App\Models\MediaItem;
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

class PlatformReadApiTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_platform_reads_require_authentication_and_resolve_to_real_controller(): void
    {
        $this->getJson('/api/build')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();

        foreach (['/api/build' => 'build', '/api/dashboard' => 'dashboard'] as $uri => $method) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertSame(PlatformReadController::class.'@'.$method, $route->getActionName());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
        }
    }

    public function test_single_tenant_build_and_dashboard_return_real_scoped_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->tenantMembership($user, 'alpha', ['execution.view']);
        $this->seedTenantData($membership, 'Alpha', posts: 2, pages: 1, media: 3, connected: true);

        $this->actingAs($user)->getJson('/api/build')
            ->assertOk()
            ->assertJsonStructure([
                'version', 'informationalVersion', 'branch', 'commit', 'buildTimeUtc', 'assemblyName',
            ]);

        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 1)
            ->assertJsonPath('posts', 2)
            ->assertJsonPath('pages', 1)
            ->assertJsonPath('media', 3)
            ->assertJsonPath('activeJobs', 0)
            ->assertJsonPath('completedJobs', 0)
            ->assertJsonPath('failedJobs', 0)
            ->assertJsonPath('healthScore', 100)
            ->assertJsonCount(0, 'recentJobs');
    }

    public function test_multi_tenant_requests_fail_closed_until_explicit_tenant_is_selected(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', ['execution.view']);
        $beta = $this->tenantMembership($user, 'beta', ['execution.view']);
        $this->seedTenantData($alpha, 'Alpha', posts: 1, pages: 0, media: 1, connected: true);
        $this->seedTenantData($beta, 'Beta', posts: 4, pages: 3, media: 2, connected: false);

        foreach (['/api/build', '/api/dashboard'] as $uri) {
            $this->actingAs($user)->getJson($uri)
                ->assertConflict()
                ->assertJsonPath('code', 'TENANT_SELECTION_REQUIRED');
        }

        $this->actingAs($user)->getJson('/api/dashboard?tenant=alpha')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 1)
            ->assertJsonPath('posts', 1)
            ->assertJsonPath('pages', 0)
            ->assertJsonPath('media', 1);

        $this->actingAs($user)->getJson('/api/dashboard?tenant=beta')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 0)
            ->assertJsonPath('posts', 4)
            ->assertJsonPath('pages', 3)
            ->assertJsonPath('media', 2);

        $this->actingAs($user)->getJson('/api/dashboard?tenant=foreign')->assertNotFound();
    }

    public function test_platform_reads_require_the_operations_read_equivalent_permission(): void
    {
        $user = User::factory()->create();
        $this->tenantMembership($user, 'limited', ['tenant.view']);

        $this->actingAs($user)->getJson('/api/build')->assertForbidden();
        $this->actingAs($user)->getJson('/api/dashboard')->assertForbidden();
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "platform-read-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function seedTenantData(
        TenantMembership $membership,
        string $prefix,
        int $posts,
        int $pages,
        int $media,
        bool $connected,
    ): void {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $prefix.' Site',
            'url' => 'https://'.strtolower($prefix).'.test',
            'status' => 'active',
            'connection_status' => $connected ? 'connected' : 'unreachable',
            'health_state' => $connected ? 'healthy' : 'degraded',
            'last_verified_at' => now()->subMinute(),
            'last_sync_at' => now()->subMinutes(2),
        ]);

        for ($index = 1; $index <= $posts; $index++) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 1000 + $index,
                'type' => 'post',
                'status' => 'publish',
                'title' => $prefix.' Post '.$index,
                'synced_at' => now()->subMinutes(3),
            ]);
        }
        for ($index = 1; $index <= $pages; $index++) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 2000 + $index,
                'type' => 'page',
                'status' => 'publish',
                'title' => $prefix.' Page '.$index,
                'synced_at' => now()->subMinutes(4),
            ]);
        }
        for ($index = 1; $index <= $media; $index++) {
            MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 3000 + $index,
                'title' => $prefix.' Media '.$index,
                'processing_state' => 'ready',
                'synced_at' => now()->subMinutes(5),
            ]);
        }

        $context->forget();
    }
}
