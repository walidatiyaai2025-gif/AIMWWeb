<?php

namespace Tests\Feature;

use App\Content\Remote\ContentRemoteDriver;
use App\Http\Controllers\ContentApiController;
use App\Models\Approval;
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
use Tests\TestCase;

final class ContentEditorSubmitApprovalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-F5686193FE';

    public function test_source_and_laravel_route_bind_exact_submit_for_approval_contract(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );

        $this->assertStringContainsString('SubmitForApprovalAsync', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.ContentEdit', $source);
        $this->assertStringContainsString('ApprovedChangePolicy.WordPressContentUpdateOperation', $source);
        $this->assertStringContainsString('ApprovalRiskLevel.Medium', $source);
        $this->assertStringContainsString('_baselineRequest', $source);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/post/101/editor/approval', 'POST'),
        );

        $this->assertSame(ContentApiController::class.'@submitEditorForApproval', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.content.editor.submit-approval', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_permission_foreign_tenant_and_foreign_site_fail_closed_without_approval_or_wordpress_write(): void
    {
        $driver = new ContentEditorApprovalFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite, $alphaItem] = $this->siteAndItem($alpha, 101, $driver->baseline(101));

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['content.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit']);
        [$betaSite, $betaItem] = $this->siteAndItem($beta, 202, $driver->baseline(202));

        $this->postJson(
            "/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/post/101/editor/approval",
            $this->payload($alphaItem),
        )->assertUnauthorized();

        $this->actingAs($viewer)->postJson(
            "/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/post/101/editor/approval",
            $this->payload($alphaItem, ['request_key' => '11111111-1111-4111-8111-111111111112']),
        )->assertForbidden();

        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/beta/sites/{$betaSite->id}/content/post/202/editor/approval",
            $this->payload($betaItem, ['request_key' => '11111111-1111-4111-8111-111111111113']),
        )->assertNotFound();

        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor/approval",
            $this->payload($betaItem, ['request_key' => '11111111-1111-4111-8111-111111111114']),
        )->assertNotFound();

        $this->assertSame(0, Approval::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, $driver->mutations);
    }

    public function test_caller_owned_identity_fields_fail_before_remote_check_or_approval_write(): void
    {
        $driver = new ContentEditorApprovalFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 303, $driver->baseline(303));

        $payload = $this->payload($item);
        $payload['tenant_id'] = $membership->tenant_id;
        $payload['site_id'] = $site->id;
        $payload['wordpress_id'] = 999;
        $payload['actor_user_id'] = 999;

        $this->actingAs($user)->postJson(
            "/api/v1/tenants/alpha/sites/{$site->id}/content/post/303/editor/approval",
            $payload,
        )->assertUnprocessable();

        $this->assertSame(0, $driver->gets);
        $this->assertSame(0, $driver->mutations);
        $this->assertSame(0, Approval::query()->withoutGlobalScopes()->count());
    }

    public function test_submit_creates_pending_medium_risk_content_approval_without_mutating_wordpress(): void
    {
        $driver = new ContentEditorApprovalFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create(['name' => 'Alpha Editor']);
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 404, $driver->baseline(404));

        $payload = $this->payload($item, [
            'title' => '<strong>Reviewed title</strong>',
            'content' => '<p>Proposed body</p>',
            'excerpt' => 'Proposed excerpt',
            'categories' => [3, 7],
            'tags' => [11],
            'sticky' => true,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/v1/tenants/alpha/sites/{$site->id}/content/post/404/editor/approval",
            $payload,
        )->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.operation_type', 'WordPressContentUpdateOperation')
            ->assertJsonPath('data.risk_level', 'Medium')
            ->assertJsonPath('replayed', false);

        $approval = Approval::query()->withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        $this->assertSame($membership->tenant_id, $approval->tenant_id);
        $this->assertSame($user->id, $approval->actor_user_id);
        $this->assertSame('Alpha Editor', $approval->actor_label);
        $this->assertSame(self::OPERATION_ID, $approval->source_operation_id);
        $this->assertSame('PENDING', $approval->status);
        $this->assertSame('Medium', $approval->risk_level);
        $this->assertSame('Original title', $approval->before_state['title']);
        $this->assertSame('Reviewed title', strip_tags($approval->proposed_state['title']));
        $this->assertSame('<p>Proposed body</p>', $approval->proposed_state['content']);
        $this->assertSame([3, 7], $approval->proposed_state['categories']);
        $this->assertSame([11], $approval->proposed_state['tags']);
        $this->assertSame($item->remote_hash, $approval->before_state['expected_hash']);
        $this->assertSame($item->remote_version, $approval->proposed_state['expected_version']);

        $this->assertSame(1, $driver->gets);
        $this->assertSame(0, $driver->mutations);
    }

    public function test_request_key_replay_is_idempotent_but_conflicting_reuse_fails_closed(): void
    {
        $driver = new ContentEditorApprovalFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 505, $driver->baseline(505));

        $payload = $this->payload($item);
        $url = "/api/v1/tenants/alpha/sites/{$site->id}/content/post/505/editor/approval";

        $first = $this->actingAs($user)->postJson($url, $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson($url, $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertJsonPath('replayed', true);
        $this->assertSame(1, Approval::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, $driver->mutations);

        $changed = $payload;
        $changed['title'] = 'Different proposal';
        $this->actingAs($user)->postJson($url, $changed)->assertConflict();

        $this->assertSame(1, Approval::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, $driver->mutations);
    }

    public function test_remote_baseline_change_returns_409_and_creates_conflict_without_approval_or_wordpress_write(): void
    {
        $driver = new ContentEditorApprovalFakeDriver;
        $this->app->instance(ContentRemoteDriver::class, $driver);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $item] = $this->siteAndItem($membership, 606, $driver->baseline(606));
        $driver->forceConflict = true;

        $this->actingAs($user)->postJson(
            "/api/v1/tenants/alpha/sites/{$site->id}/content/post/606/editor/approval",
            $this->payload($item),
        )->assertConflict()->assertJsonStructure(['message', 'conflict_id']);

        $this->assertSame(1, $driver->gets);
        $this->assertSame(0, $driver->mutations);
        $this->assertSame(0, Approval::query()->withoutGlobalScopes()->count());

        $this->activate($membership);
        $this->assertSame(
            1,
            ContentConflict::query()->where('site_id', $site->id)->where('status', 'open')->count(),
        );
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
            'request_key' => '11111111-1111-4111-8111-111111111111',
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

final class ContentEditorApprovalFakeDriver implements ContentRemoteDriver
{
    public int $gets = 0;

    public int $mutations = 0;

    public bool $forceConflict = false;

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
                'version' => 'v2',
            ]);
        }

        return $this->baseline($remoteId);
    }

    public function mutate(int $siteId, string $resource, ?int $remoteId, string $action, array $payload = []): array
    {
        $this->mutations++;

        return $this->baseline((int) $remoteId);
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
