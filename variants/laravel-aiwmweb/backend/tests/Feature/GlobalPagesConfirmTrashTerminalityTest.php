<?php

namespace Tests\Feature;

use App\Content\Remote\ContentRemoteDriver;
use App\Http\Controllers\GlobalPagesTrashController;
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

final class GlobalPagesConfirmTrashTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-C499965699';

    public function test_source_contract_route_and_visible_control_are_bound(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $widget = (string) file_get_contents(resource_path('js/global-pages-confirm-trash-widget.tsx'));

        $this->assertStringContainsString('ConfirmTrashAsync', $source);
        $this->assertStringContainsString('GlobalContentBulkPolicy.GroupBySite', $source);
        $this->assertStringContainsString('TrashService.RunAsync', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);
        $this->assertStringContainsString('GlobalPagesConfirmTrashControl', $app);
        $this->assertStringContainsString('GLOBAL_PAGES_CONFIRM_TRASH_OPERATION_ID', $widget);
        $this->assertStringContainsString('setConfirmOpen(true)', $widget);
        $this->assertStringContainsString('mutation.mutate(selectedTargets)', $widget);
        $this->assertStringContainsString('await query.refetch()', $widget);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/global-pages/trash', 'POST'),
        );

        $this->assertSame(GlobalPagesTrashController::class.'@trash', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.global-pages.trash', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $workspace = Route::getRoutes()->getByName('canonical.workspace.pages');
        $this->assertNotNull($workspace);
        $this->assertStringEndsWith('@show', ltrim((string) $workspace->getActionName(), '\\'));
    }

    public function test_guest_viewer_foreign_tenant_and_foreign_site_fail_closed_without_remote_mutation(): void
    {
        $driver = new GlobalPagesTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite] = $this->siteAndPage($alpha, 101, $driver);

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['content.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit']);
        [$betaSite] = $this->siteAndPage($beta, 202, $driver);

        $payload = ['targets' => [['site_id' => $alphaSite->id, 'wordpress_id' => 101]]];

        $this->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)
            ->assertUnauthorized();

        $this->actingAs($viewer)
            ->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)
            ->assertForbidden();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/beta/global-pages/trash', [
                'targets' => [['site_id' => $betaSite->id, 'wordpress_id' => 202]],
            ])
            ->assertNotFound();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/alpha/global-pages/trash', [
                'targets' => [['site_id' => $betaSite->id, 'wordpress_id' => 202]],
            ])
            ->assertUnprocessable();

        $this->assertSame(0, $driver->mutations);
    }

    public function test_identity_injection_duplicates_and_unavailable_pages_are_rejected_before_mutation(): void
    {
        $driver = new GlobalPagesTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site] = $this->siteAndPage($membership, 303, $driver);

        $this->actingAs($user)->postJson('/api/v1/tenants/alpha/global-pages/trash', [
            'tenant_id' => $membership->tenant_id,
            'targets' => [['site_id' => $site->id, 'wordpress_id' => 303]],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/api/v1/tenants/alpha/global-pages/trash', [
            'targets' => [
                ['site_id' => $site->id, 'wordpress_id' => 303],
                ['site_id' => $site->id, 'wordpress_id' => 303],
            ],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/api/v1/tenants/alpha/global-pages/trash', [
            'targets' => [['site_id' => $site->id, 'wordpress_id' => 999]],
        ])->assertUnprocessable();

        $this->assertSame(0, $driver->mutations);
    }

    public function test_multi_site_confirm_trash_rereads_authoritative_state_and_retry_is_idempotent(): void
    {
        $driver = new GlobalPagesTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$siteA, $pageA] = $this->siteAndPage($membership, 401, $driver);
        [$siteB, $pageB] = $this->siteAndPage($membership, 402, $driver);

        $payload = ['targets' => [
            ['site_id' => $siteA->id, 'wordpress_id' => 401],
            ['site_id' => $siteB->id, 'wordpress_id' => 402],
        ]];

        $this->actingAs($user)
            ->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)
            ->assertOk()
            ->assertJsonPath('succeeded', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('results.0.status', 'trashed')
            ->assertJsonPath('results.1.status', 'trashed')
            ->assertJsonPath('message', 'Selected pages moved to trash.');

        $this->assertSame('trash', $pageA->fresh()->status);
        $this->assertSame('trash', $pageB->fresh()->status);
        $this->assertSame(2, $driver->mutations);
        $this->assertGreaterThanOrEqual(4, $driver->gets);

        $this->actingAs($user)
            ->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)
            ->assertOk()
            ->assertJsonPath('succeeded', 2)
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('results.0.status', 'already_trashed')
            ->assertJsonPath('results.1.status', 'already_trashed');

        $this->assertSame(2, $driver->mutations);
    }

    public function test_global_page_read_is_tenant_scoped_and_exposes_site_context_without_secret_fields(): void
    {
        $driver = new GlobalPagesTrashFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite] = $this->siteAndPage($alpha, 501, $driver);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['content.view', 'content.edit']);
        $this->siteAndPage($beta, 502, $driver);

        $response = $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha/global-pages?per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $alphaSite->id)
            ->assertJsonPath('data.0.wordpress_id', 501);

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('tenant_id', $row);
        $this->assertArrayNotHasKey('metadata', $row);
        $this->assertArrayNotHasKey('body', $row);
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

    private function siteAndPage(
        TenantMembership $membership,
        int $remoteId,
        GlobalPagesTrashFakeDriver $driver,
    ): array {
        app(TenantContext::class)->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => 'Global '.$remoteId,
            'url' => "https://global-{$remoteId}.example.test",
            'status' => 'active',
            'connection_status' => 'connected',
        ]);

        $remote = $driver->baseline($site->id, $remoteId);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'page',
            'title' => 'Page '.$remoteId,
            'slug' => 'page-'.$remoteId,
            'body' => '<p>Body</p>',
            'excerpt' => 'Excerpt',
            'status' => 'draft',
            'remote_hash' => $this->remoteHash($remote),
            'remote_modified_at' => $remote['modified_gmt'],
            'remote_version' => $remote['version'],
            'synced_at' => now(),
            'metadata' => [],
        ]);
        app(TenantContext::class)->forget();

        return [$site, $item];
    }

    private function remoteHash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

final class GlobalPagesTrashFakeDriver implements ContentRemoteDriver
{
    public int $gets = 0;

    public int $mutations = 0;

    /** @var array<string, string> */
    private array $statuses = [];

    public function baseline(int $siteId, int $remoteId): array
    {
        $key = $siteId.':'.$remoteId;
        $status = $this->statuses[$key] ?? 'draft';

        return [
            'id' => $remoteId,
            'title' => ['raw' => 'Page '.$remoteId],
            'slug' => 'page-'.$remoteId,
            'status' => $status,
            'content' => ['raw' => '<p>Body</p>'],
            'excerpt' => ['raw' => 'Excerpt'],
            'featured_media' => 0,
            'categories' => [],
            'tags' => [],
            'template' => '',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'format' => 'standard',
            'sticky' => false,
            'modified_gmt' => '2026-10-08T01:00:00Z',
            'version' => 'v1',
            'link' => "https://wordpress.example.test/?page_id={$remoteId}",
        ];
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        $this->gets++;

        return $this->baseline($siteId, $remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;

        if ($action !== 'trash' || $remoteId === null) {
            throw new RuntimeException('unexpected fake mutation');
        }

        $this->statuses[$siteId.':'.$remoteId] = 'trash';

        return $this->baseline($siteId, $remoteId);
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
