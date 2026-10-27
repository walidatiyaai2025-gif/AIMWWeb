<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\ContentApiController;
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

final class ContentEditorRouteTerminalityTest extends TestCase
{
    // Final exact-head CI trigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-2C02B7A328';

    public function test_exact_canonical_operation_matches_the_content_editor_open_route(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_operation_bound_guarded_and_read_only(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/7/content/post/101/edit', 'GET'),
        );

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showContentEditor',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.site.content-editor', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('content.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site', 'contentType', 'wordpressId'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_authorized_route_opens_real_editor_and_reads_tenant_scoped_content(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view']);
        [$site, $item] = $this->siteAndItem($membership, 101);
        $this->withoutVite();

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/content/post/101/edit")
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSessionHas('canonical_site_id', $site->id);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/content/post/101/editor")
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('wordpress_id', 101)
            ->assertJsonPath('type', 'post')
            ->assertJsonPath('title', 'Route parity title')
            ->assertJsonPath('slug', 'route-parity-title')
            ->assertJsonPath('content', '<p>Authoritative synchronized body</p>')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('expected_version', 'wp-v1');
    }

    public function test_loading_not_found_and_failure_states_are_deterministic_without_fake_success(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $editor = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString(
            '@page "/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit"',
            $source,
        );
        $this->assertStringContainsString('if (_loading)', $source);
        $this->assertStringContainsString('else if (_site is null || _model is null)', $source);
        $this->assertStringContainsString('EditorService.GetAsync(SiteId, ContentType, WordPressId)', $source);

        $this->assertStringContainsString(
            '<Route path="sites/:siteId/content/:contentType/:wordpressId/edit" element={<ContentEditorRoute />} />',
            $app,
        );
        $this->assertStringContainsString('return <ContentEditorSaveControl context={context} />;', $app);
        $this->assertStringContainsString('Loading authoritative content…', $editor);
        $this->assertStringContainsString('Content could not be loaded.', $editor);
        $this->assertStringContainsString("throw new ApiError('Invalid content editor route.'", $editor);
        $this->assertStringContainsString('await apiRequest<ContentEditorSnapshot>(endpoint)', $editor);
        $this->assertStringNotContainsString('fake success', strtolower($editor));
    }

    public function test_missing_permission_invalid_route_and_cross_tenant_site_fail_closed(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view']);
        [$alphaSite] = $this->siteAndItem($alpha, 101);

        $limited = User::factory()->create();
        $this->membershipExisting($limited, $alpha->tenant, [], 'limited');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view']);
        [$betaSite] = $this->siteAndItem($beta, 202);

        $this->actingAs($limited)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/content/post/101/edit")
            ->assertForbidden();

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/beta/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/content/article/101/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/content/post/not-a-number/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();
    }

    public function test_existing_save_and_approval_mutations_remain_separate_operations(): void
    {
        $save = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/post/101/editor', 'PATCH'),
        );
        $approval = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/post/101/editor/approval', 'POST'),
        );

        $this->assertSame(ContentApiController::class.'@saveEditor', ltrim($save->getActionName(), '\\'));
        $this->assertSame(ContentApiController::CONTENT_EDITOR_SAVE_OPERATION_ID, $save->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $save->defaults['canonical_operation_id'] ?? null);

        $this->assertSame(ContentApiController::class.'@submitEditorForApproval', ltrim($approval->getActionName(), '\\'));
        $this->assertSame(ContentApiController::CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID, $approval->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $approval->defaults['canonical_operation_id'] ?? null);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');
    }

    /** @param list<string> $permissions */
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

    /** @return array{0: Site, 1: ContentItem} */
    private function siteAndItem(TenantMembership $membership, int $remoteId): array
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);

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
            'title' => 'Route parity title',
            'slug' => 'route-parity-title',
            'body' => '<p>Authoritative synchronized body</p>',
            'excerpt' => 'Route parity excerpt',
            'status' => 'draft',
            'remote_hash' => hash('sha256', 'route-parity-'.$remoteId),
            'remote_modified_at' => '2026-10-24T06:00:00Z',
            'remote_version' => 'wp-v1',
            'synced_at' => now(),
            'metadata' => ['categories' => [3], 'tags' => [7]],
        ]);

        app(TenantContext::class)->forget();

        return [$site, $item];
    }
}
