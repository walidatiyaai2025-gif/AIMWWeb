<?php

namespace Tests\Feature;

use App\Content\Remote\ContentRemoteDriver;
use App\Http\Controllers\ContentApiController;
use App\Models\ContentConflict;
use App\Models\ContentItem;
use App\Models\ContentRevision;
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

final class ContentEditorSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-42F7590F00';

    public function test_source_and_laravel_route_bind_exact_save_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );
        $this->assertStringContainsString('@page "/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit"', $source);
        $this->assertStringContainsString('@onclick="SaveAsync"', $source);
        $this->assertStringContainsString('CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit)', $source);
        $this->assertStringContainsString('EditorService.UpdateAsync(SiteId, _model.ToRequest(expected))', $source);
        $this->assertStringContainsString('await SyncService.SynchronizeAsync(SiteId)', $source);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/post/101/editor', 'PATCH'),
        );

        $this->assertSame(ContentApiController::class.'@saveEditor', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.content.editor.save', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $frontend = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("method: 'PATCH'", $frontend);
        $this->assertStringContainsString('await reload()', $frontend);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $driver = new ContentEditorFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite, $alphaItem] = $this->siteAndItem($alpha, 101, $driver->baseline(101));

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['content.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit']);
        [$betaSite, $betaItem] = $this->siteAndItem($beta, 202, $driver->baseline(202));

        $payload = $this->payload($alphaItem);

        $this->patchJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/post/101/editor", $payload)
            ->assertUnauthorized();

        $this->actingAs($viewer)
            ->patchJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/post/101/editor", $payload)
            ->assertForbidden();

        $this->actingAs($editor)
            ->patchJson("/api/v1/tenants/beta/sites/{$betaSite->id}/content/post/202/editor", $this->payload($betaItem))
            ->assertNotFound();

        $this->actingAs($editor)
            ->patchJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor", $this->payload($betaItem))
            ->assertNotFound();

        $this->actingAs($editor)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();

        $this->assertSame(0, $driver->mutations);
    }

    public function test_save_rejects_caller_owned_identity_fields_before_remote_mutation(): void
    {
        $driver = new ContentEditorFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 303, $driver->baseline(303));

        $payload = $this->payload($item);
        $payload['tenant_id'] = $membership->tenant_id;
        $payload['remote_id'] = 999999;
        $payload['actor_user_id'] = 999999;

        $this->actingAs($user)
            ->patchJson("/api/v1/tenants/alpha/sites/{$site->id}/content/post/303/editor", $payload)
            ->assertUnprocessable();

        $this->assertSame(0, $driver->gets);
        $this->assertSame(0, $driver->mutations);
    }

    public function test_save_mutates_real_driver_then_rereads_and_persists_authoritative_wordpress_state(): void
    {
        $driver = new ContentEditorFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 404, $driver->baseline(404));

        $payload = $this->payload($item, [
            'title' => 'Updated title',
            'content' => '<p>Updated body</p>',
            'excerpt' => 'Updated excerpt',
            'categories' => [3, 7],
            'tags' => [11],
            'sticky' => true,
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/api/v1/tenants/alpha/sites/{$site->id}/content/post/404/editor", $payload)
            ->assertOk()
            ->assertJsonPath('wordpress_id', 404)
            ->assertJsonPath('type', 'post')
            ->assertJsonPath('title', 'Updated title')
            ->assertJsonPath('content', '<p>Updated body</p>')
            ->assertJsonPath('categories.0', 3)
            ->assertJsonPath('categories.1', 7)
            ->assertJsonPath('tags.0', 11)
            ->assertJsonPath('sticky', true)
            ->assertJsonPath('expected_version', 'v2');

        $this->assertNotEmpty($response->json('expected_hash'));
        $this->assertNotEmpty($response->json('expected_modified_at'));
        $this->assertSame(2, $driver->gets);
        $this->assertSame(1, $driver->mutations);
        $this->assertSame(404, $driver->lastMutationRemoteId);
        $this->assertSame('posts', $driver->lastMutationResource);
        $this->assertSame('update', $driver->lastMutationAction);

        $this->activate($membership);
        $saved = ContentItem::query()->where('site_id', $site->id)->where('remote_id', 404)->firstOrFail();
        $this->assertSame('Updated title', $saved->title);
        $this->assertSame('<p>Updated body</p>', $saved->body);
        $this->assertSame('v2', $saved->remote_version);
        $this->assertSame([3, 7], array_values($saved->metadata['categories'] ?? []));
        $this->assertSame([11], array_values($saved->metadata['tags'] ?? []));
        $this->assertSame(2, ContentRevision::query()->where('content_item_id', $saved->id)->count());
        $this->assertSame(
            ['local-before-mutation', 'wordpress-sync'],
            ContentRevision::query()
                ->where('content_item_id', $saved->id)
                ->orderBy('id')
                ->pluck('source')
                ->all(),
        );
        $this->assertDatabaseHas('audit_events', [
            'event' => 'content.update',
            'subject_type' => 'post',
            'subject_id' => '404',
        ]);
        app(TenantContext::class)->forget();
    }

    public function test_remote_version_conflict_returns_409_and_never_overwrites_newer_wordpress_content(): void
    {
        $driver = new ContentEditorFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 505, $driver->baseline(505));

        $driver->forceConflict = true;

        $this->actingAs($user)
            ->patchJson(
                "/api/v1/tenants/alpha/sites/{$site->id}/content/post/505/editor",
                $this->payload($item, ['title' => 'Must not overwrite']),
            )
            ->assertConflict()
            ->assertJsonStructure(['message', 'conflict_id']);

        $this->assertSame(1, $driver->gets);
        $this->assertSame(0, $driver->mutations);

        $this->activate($membership);
        $this->assertSame(1, ContentConflict::query()->where('site_id', $site->id)->where('status', 'open')->count());
        $this->assertSame('Original title', ContentItem::query()->findOrFail($item->id)->title);
        app(TenantContext::class)->forget();
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

    private function siteAndItem(TenantMembership $membership, int $remoteId, array $remote): array
    {
        $this->activate($membership);

        $site = Site::query()->create([
            'name' => 'Editor '.$remoteId,
            'url' => "https://editor-{$remoteId}.example.test",
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'title' => 'Original title',
            'slug' => 'original-title',
            'body' => '<p>Original body</p>',
            'excerpt' => 'Original excerpt',
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

    private function payload(ContentItem $item, array $overrides = []): array
    {
        return array_replace([
            'title' => 'Original title',
            'slug' => 'original-title',
            'content' => '<p>Original body</p>',
            'excerpt' => 'Original excerpt',
            'status' => 'draft',
            'date_gmt' => null,
            'featured_media' => 0,
            'categories' => [1],
            'tags' => [2],
            'template' => null,
            'comment_status' => 'open',
            'ping_status' => 'open',
            'format' => 'standard',
            'sticky' => false,
            'expected_hash' => $item->remote_hash,
            'expected_modified_at' => $item->remote_modified_at?->toIso8601String(),
            'expected_version' => $item->remote_version,
        ], $overrides);
    }

    private function remoteHash(array $row): string
    {
        ksort($row);

        return hash('sha256', json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

final class ContentEditorFakeDriver implements ContentRemoteDriver
{
    public int $gets = 0;

    public int $mutations = 0;

    public bool $forceConflict = false;

    public ?int $lastMutationRemoteId = null;

    public ?string $lastMutationResource = null;

    public ?string $lastMutationAction = null;

    /** @var array<int, array<string, mixed>> */
    private array $after = [];

    public function baseline(int $remoteId): array
    {
        return [
            'id' => $remoteId,
            'title' => ['raw' => 'Original title'],
            'slug' => 'original-title',
            'status' => 'draft',
            'content' => ['raw' => '<p>Original body</p>'],
            'excerpt' => ['raw' => 'Original excerpt'],
            'featured_media' => 0,
            'categories' => [1],
            'tags' => [2],
            'template' => '',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'format' => 'standard',
            'sticky' => false,
            'modified_gmt' => '2026-10-07T12:00:00Z',
            'version' => 'v1',
            'link' => "https://wordpress.example.test/?p={$remoteId}",
        ];
    }

    public function list(int $siteId, string $resource, array $query = []): array
    {
        return [];
    }

    public function get(int $siteId, string $resource, int $remoteId, array $query = []): array
    {
        $this->gets++;

        if ($this->forceConflict) {
            return array_replace($this->baseline($remoteId), [
                'title' => ['raw' => 'Changed remotely'],
                'modified_gmt' => '2026-10-07T12:30:00Z',
                'version' => 'v-newer',
            ]);
        }

        return $this->after[$remoteId] ?? $this->baseline($remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;
        $this->lastMutationRemoteId = $remoteId;
        $this->lastMutationResource = $resource;
        $this->lastMutationAction = $action;

        $id = (int) $remoteId;
        $remote = array_replace($this->baseline($id), $payload, [
            'id' => $id,
            'modified_gmt' => '2026-10-07T12:05:00Z',
            'version' => 'v2',
            'link' => "https://wordpress.example.test/?p={$id}",
        ]);
        $this->after[$id] = $remote;

        return $remote;
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
