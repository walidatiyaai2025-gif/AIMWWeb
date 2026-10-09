<?php

namespace Tests\Feature;

use App\Content\Remote\ContentRemoteDriver;
use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sync\GlobalSynchronizationConflictReviewService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationReviewConflictsTerminalityTest extends TestCase
{
    // Exact-head human trigger after parity materialization.
    use RefreshDatabase;

    private FakeReviewRemoteDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new FakeReviewRemoteDriver;
        $this->app->instance(ContentRemoteDriver::class, $this->driver);
    }

    public function test_source_contract_and_route_bind_exact_review_conflicts_operation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-review-conflicts-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('OnClick="ReviewConflictsAsync"', $source);
        $this->assertStringContainsString('_conflictReview = await SyncService.ReviewConflictsAsync(_selectedSiteId);', $source);
        $this->assertStringContainsString(GlobalSynchronizationConflictReviewService::OPERATION_ID, $frontend);
        $this->assertStringContainsString('GlobalSynchronizationReviewConflictsControl', $app);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/sync/review-conflicts', 'GET'));
        $this->assertSame('App\\Http\\Controllers\\SyncApiController@reviewConflicts', ltrim($route->getActionName(), '\\'));
        $this->assertSame(GlobalSynchronizationConflictReviewService::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_review_compares_live_wordpress_without_mutating_cache_or_starting_sync(): void
    {
        $owner = User::factory()->create();
        $tenant = $this->membership($owner, 'alpha', ['content.view']);
        $site = $this->site($tenant, 'Alpha Site');

        $this->activate($tenant);
        $local = ContentItem::query()->create([
            'site_id' => $site->id,
            'type' => 'post',
            'remote_id' => 10,
            'title' => 'Local title',
            'slug' => 'local-title',
            'status' => 'publish',
            'link' => 'https://alpha.example.test/local-title',
            'body' => 'Local body',
            'excerpt' => 'Local excerpt',
            'remote_modified_at' => '2026-10-08T10:00:00Z',
            'synced_at' => '2026-10-08T10:00:00Z',
            'stale' => false,
        ]);
        app(TenantContext::class)->forget();

        $this->driver->posts = [
            $this->remoteContent(10, 'Remote title', 'local-title', '2026-10-09T10:00:00Z'),
            $this->remoteContent(11, 'New remote', 'new-remote', '2026-10-09T11:00:00Z'),
        ];

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/sync/review-conflicts")
            ->assertOk()
            ->assertJsonPath('operation_id', GlobalSynchronizationConflictReviewService::OPERATION_ID)
            ->assertJsonPath('has_baseline', true)
            ->assertJsonPath('remote_updates', 1)
            ->assertJsonPath('remote_deletions', 0)
            ->assertJsonPath('remote_additions', 1)
            ->assertJsonPath('has_conflicts', true)
            ->assertJsonPath('conflicts.0.wordpress_id', 10)
            ->assertJsonPath('conflicts.0.kind', 'RemoteUpdated');

        $this->assertSame('Local title', $local->fresh()->title);
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('content_conflicts', 0);
        $this->assertSame(0, $this->driver->mutations);
        $this->assertSame(2, $this->driver->lists);
        $this->assertSame(10, $response->json('conflicts.0.wordpress_id'));
    }

    public function test_review_is_authorized_and_foreign_site_fails_closed(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha, [], 'no-content-view');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($viewer)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/review-conflicts")
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/sync/review-conflicts")
            ->assertNotFound();

        $this->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/review-conflicts")
            ->assertUnauthorized();
    }

    private function remoteContent(int $id, string $title, string $slug, string $modified): array
    {
        return [
            'id' => $id,
            'title' => ['rendered' => $title],
            'slug' => $slug,
            'status' => 'publish',
            'link' => "https://alpha.example.test/{$slug}",
            'content' => ['raw' => 'Remote body'],
            'excerpt' => ['raw' => 'Remote excerpt'],
            'modified_gmt' => $modified,
        ];
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
        $this->activate($tenant);
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

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}

final class FakeReviewRemoteDriver implements ContentRemoteDriver
{
    public array $posts = [];

    public array $pages = [];

    public int $lists = 0;

    public int $mutations = 0;

    public function list(int $siteId, string $resource, array $query = []): array
    {
        $this->lists++;
        return $resource === 'posts' ? $this->posts : ($resource === 'pages' ? $this->pages : []);
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        return [];
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;
        return [];
    }

    public function upload(int $siteId, string $path, string $name, string $mimeType, array $metadata = []): array
    {
        return [];
    }

    public function semantic(int $siteId, string $operation, array $payload = []): array
    {
        return [];
    }
}
