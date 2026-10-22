<?php

namespace Tests\Feature;

use App\Content\Remote\ContentRemoteDriver;
use App\Http\Controllers\ContentApiController;
use App\Models\ContentConflict;
use App\Models\ContentItem;
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
use RuntimeException;
use Tests\TestCase;

final class ContentExplorerBulkTrashTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-452B93663B';

    public function test_source_contract_route_and_visible_laravel_control_are_bound(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $widget = (string) file_get_contents(resource_path('js/content-explorer-bulk-trash-widget.tsx'));

        $this->assertStringContainsString('BulkTrashAsync', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.ContentEdit', $source);
        $this->assertStringContainsString('TrashService.RunAsync', $source);
        $this->assertStringContainsString('ContentExplorerBulkTrashControl', $app);
        $this->assertStringContainsString('CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID', $widget);
        $this->assertStringContainsString('mutation.mutate(selectedTargets)', $widget);
        $this->assertStringContainsString('postsQuery.refetch()', $widget);
        $this->assertStringContainsString('pagesQuery.refetch()', $widget);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/bulk/trash', 'POST'),
        );

        $this->assertSame(ContentApiController::class.'@bulkTrash', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.content.explorer.bulk-trash', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_permission_foreign_tenant_and_foreign_site_fail_closed_without_remote_mutation(): void
    {
        $driver = new ContentExplorerBulkTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite] = $this->siteAndItem($alpha, 101, 'post', $driver);

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['content.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit']);
        [$betaSite] = $this->siteAndItem($beta, 202, 'post', $driver);

        $payload = ['targets' => [['content_type' => 'post', 'wordpress_id' => 101]]];

        $this->postJson(
            "/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/bulk/trash",
            $payload,
        )->assertUnauthorized();

        $this->actingAs($viewer)->postJson(
            "/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/bulk/trash",
            $payload,
        )->assertForbidden();

        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/beta/sites/{$betaSite->id}/content/bulk/trash",
            ['targets' => [['content_type' => 'post', 'wordpress_id' => 202]]],
        )->assertNotFound();

        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/alpha/sites/{$betaSite->id}/content/bulk/trash",
            ['targets' => [['content_type' => 'post', 'wordpress_id' => 202]]],
        )->assertNotFound();

        $this->assertSame(0, $driver->gets);
        $this->assertSame(0, $driver->mutations);
    }

    public function test_identity_injection_duplicate_targets_and_unavailable_content_are_rejected_before_remote_mutation(): void
    {
        $driver = new ContentExplorerBulkTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site] = $this->siteAndItem($membership, 303, 'post', $driver);
        $url = "/api/v1/tenants/alpha/sites/{$site->id}/content/bulk/trash";

        $this->actingAs($user)->postJson($url, [
            'tenant_id' => $membership->tenant_id,
            'targets' => [['content_type' => 'post', 'wordpress_id' => 303]],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson($url, [
            'targets' => [
                ['content_type' => 'post', 'wordpress_id' => 303],
                ['content_type' => 'post', 'wordpress_id' => 303],
            ],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson($url, [
            'targets' => [['content_type' => 'page', 'wordpress_id' => 999]],
        ])->assertUnprocessable();

        $this->assertSame(0, $driver->gets);
        $this->assertSame(0, $driver->mutations);
    }

    public function test_mixed_post_and_page_selection_is_trashed_and_returns_authoritative_summary(): void
    {
        $driver = new ContentExplorerBulkTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $post] = $this->siteAndItem($membership, 404, 'post', $driver);
        [, $page] = $this->siteAndItem($membership, 405, 'page', $driver, $site);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/tenants/alpha/sites/{$site->id}/content/bulk/trash",
            ['targets' => [
                ['content_type' => 'post', 'wordpress_id' => 404],
                ['content_type' => 'page', 'wordpress_id' => 405],
            ]],
        )->assertOk()
            ->assertJsonPath('succeeded', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('results.0.status', 'trashed')
            ->assertJsonPath('results.1.status', 'trashed');

        $this->assertSame('Selected content moved to trash.', $response->json('message'));
        $this->assertSame('trash', $post->fresh()->status);
        $this->assertSame('trash', $page->fresh()->status);
        $this->assertSame(2, $driver->gets);
        $this->assertSame(2, $driver->mutations);
    }

    public function test_conflict_and_remote_failure_are_isolated_while_other_targets_continue(): void
    {
        $driver = new ContentExplorerBulkTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $conflict] = $this->siteAndItem($membership, 501, 'post', $driver);
        [, $failed] = $this->siteAndItem($membership, 502, 'page', $driver, $site);
        [, $success] = $this->siteAndItem($membership, 503, 'post', $driver, $site);

        $driver->conflictRemoteId = 501;
        $driver->failMutationRemoteId = 502;

        $this->actingAs($user)->postJson(
            "/api/v1/tenants/alpha/sites/{$site->id}/content/bulk/trash",
            ['targets' => [
                ['content_type' => 'post', 'wordpress_id' => 501],
                ['content_type' => 'page', 'wordpress_id' => 502],
                ['content_type' => 'post', 'wordpress_id' => 503],
            ]],
        )->assertOk()
            ->assertJsonPath('succeeded', 1)
            ->assertJsonPath('failed', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.1.status', 'failed')
            ->assertJsonPath('results.2.status', 'trashed')
            ->assertJsonPath('message', 'Bulk trash completed with failures.');

        $this->assertSame('draft', $conflict->fresh()->status);
        $this->assertSame('draft', $failed->fresh()->status);
        $this->assertSame('trash', $success->fresh()->status);

        $this->activate($membership);
        $this->assertSame(
            1,
            ContentConflict::query()->where('site_id', $site->id)->where('status', 'open')->count(),
        );
        app(TenantContext::class)->forget();

        $this->assertSame(3, $driver->gets);
        $this->assertSame(2, $driver->mutations);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        app(TenantContext::class)->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function siteAndItem(
        TenantMembership $membership,
        int $remoteId,
        string $type,
        ContentExplorerBulkTrashFakeDriver $driver,
        ?Site $site = null,
    ): array {
        $this->activate($membership);

        $site ??= Site::query()->create([
            'name' => 'Explorer '.$remoteId,
            'url' => "https://explorer-{$remoteId}.example.test",
            'status' => 'active',
            'connection_status' => 'connected',
        ]);

        $remote = $driver->baseline($remoteId, $type);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => $type,
            'title' => ucfirst($type).' '.$remoteId,
            'slug' => $type.'-'.$remoteId,
            'body' => '<p>Body</p>',
            'excerpt' => 'Excerpt',
            'status' => 'draft',
            'remote_hash' => $this->remoteHash($remote),
            'remote_modified_at' => $remote['modified_gmt'],
            'remote_version' => $remote['version'],
            'synced_at' => now(),
            'metadata' => ['categories' => [1], 'tags' => [2]],
        ]);

        app(TenantContext::class)->forget();

        return [$site, $item];
    }

    private function activate(TenantMembership $membership): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
    }

    private function remoteHash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

final class ContentExplorerBulkTrashFakeDriver implements ContentRemoteDriver
{
    public int $gets = 0;

    public int $mutations = 0;

    public ?int $conflictRemoteId = null;

    public ?int $failMutationRemoteId = null;

    public function baseline(int $remoteId, string $type): array
    {
        return [
            'id' => $remoteId,
            'title' => ['raw' => ucfirst($type).' '.$remoteId],
            'slug' => $type.'-'.$remoteId,
            'status' => 'draft',
            'content' => ['raw' => '<p>Body</p>'],
            'excerpt' => ['raw' => 'Excerpt'],
            'featured_media' => 0,
            'categories' => [1],
            'tags' => [2],
            'template' => '',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'format' => 'standard',
            'sticky' => false,
            'modified_gmt' => '2026-10-08T01:00:00Z',
            'version' => 'v1',
            'link' => "https://wordpress.example.test/?{$type}={$remoteId}",
        ];
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        $this->gets++;
        $type = $resource === 'pages' ? 'page' : 'post';
        $row = $this->baseline($remoteId, $type);

        if ($this->conflictRemoteId === $remoteId) {
            $row['title'] = ['raw' => 'Changed remotely'];
            $row['modified_gmt'] = '2026-10-08T01:30:00Z';
            $row['version'] = 'v2';
        }

        return $row;
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;

        if ($this->failMutationRemoteId === (int) $remoteId) {
            throw new RuntimeException('simulated WordPress mutation failure');
        }

        $type = $resource === 'pages' ? 'page' : 'post';

        return array_replace($this->baseline((int) $remoteId, $type), ['status' => 'trash']);
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
