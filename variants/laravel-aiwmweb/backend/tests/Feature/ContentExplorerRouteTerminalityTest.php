<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\ContentExplorerReadController;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-5CF47CBE2A';

    public function test_exact_canonical_operation_matches_content_explorer_open_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/explorer', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_workspace_and_read_api_are_explicit_site_bound_read_only_contracts(): void
    {
        $workspace = Route::getRoutes()->match(
            Request::create('/tenants/alpha/explorer?site=7', 'GET'),
        );
        $api = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/explorer', 'GET'),
        );

        $this->assertSame('canonical.workspace.explorer', $workspace->getName());
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSiteBound',
            ltrim($workspace->getActionName(), '\\'),
        );
        $this->assertSame(self::OPERATION_ID, $workspace->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('sites.view', $workspace->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $workspace->methods());

        $this->assertSame('canonical.api.content-explorer.read', $api->getName());
        $this->assertSame(ContentExplorerReadController::class, ltrim($api->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $api->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $api->gatherMiddleware());
        $this->assertContains('tenant.context', $api->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $api->methods());
    }

    public function test_authorized_workspace_renders_and_reads_only_authoritative_selected_site_data(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Explorer');
        $post = $this->content($membership, $site, 101, 'post', 'Alpha Post');
        $this->content($membership, $site, 202, 'page', 'Alpha Page');
        $this->taxonomy($membership, $site, 301, 'category', 'News');
        $this->taxonomy($membership, $site, 302, 'post_tag', 'Laravel');
        $this->media($membership, $site, 401, 'Hero');

        $before = ContentItem::withoutGlobalScopes()->findOrFail($post->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/explorer?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSessionHas('canonical_site_id', $site->id);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('api.explorer', "/api/v1/tenants/alpha/sites/{$site->id}/explorer");

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/explorer")
            ->assertOk()
            ->assertJsonPath('meta.operation_id', self::OPERATION_ID)
            ->assertJsonPath('meta.site.id', $site->id)
            ->assertJsonPath('meta.site.name', 'Alpha Explorer')
            ->assertJsonPath('meta.totals.posts', 1)
            ->assertJsonPath('meta.totals.pages', 1)
            ->assertJsonPath('meta.totals.categories', 1)
            ->assertJsonPath('meta.totals.tags', 1)
            ->assertJsonPath('meta.totals.media', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonFragment([
                'wordpress_id' => 101,
                'type' => 'post',
                'title' => 'Alpha Post',
                'status' => 'draft',
            ]);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($post->id)->toArray());
    }

    public function test_missing_permission_foreign_site_and_foreign_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Explorer');

        $this->withoutVite();
        $this->actingAs($limited)
            ->get('/tenants/limited/explorer?site='.$limitedSite->id)
            ->assertForbidden();
        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/limited/sites/{$limitedSite->id}/explorer")
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($owner)
            ->get('/tenants/alpha/explorer?site='.$alphaSite->id)
            ->assertOk();
        $this->actingAs($owner)
            ->get('/tenants/alpha/explorer?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/explorer")
            ->assertNotFound();
        $this->actingAs($owner)
            ->get('/tenants/beta/explorer?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($owner)
            ->get('/tenants/alpha/explorer?site=not-a-number')
            ->assertUnprocessable();
    }

    public function test_source_and_laravel_rendering_keep_loading_empty_error_and_real_data_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $web = (string) file_get_contents(base_path('routes/web.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentExplorerReadController.php'));

        $this->assertStringContainsString('@page "/sites/{Id:guid}/explorer"', $source);
        $this->assertStringContainsString('_loading && _data is null', $source);
        $this->assertStringContainsString('else if (_site is null)', $source);
        $this->assertStringContainsString('GetExplorerAsync(Id, _query, _type)', $source);

        $this->assertStringContainsString("r('explorer', '/explorer'", $core);
        $this->assertStringContainsString("route.key === 'explorer'", $app);
        $this->assertStringContainsString('<WorkspacePage context={context} route={route} />', $app);
        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('if (query.error) return (', $pages);
        $this->assertStringContainsString('No sample rows are synthesized when the server returns an empty result.', $pages);
        $this->assertStringContainsString('\'explorer\' => "/api/v1/tenants/{$tenant}/sites/{$siteId}/explorer"', $web);
        $this->assertStringContainsString("authorize('sites.view')", $controller);
        $this->assertStringContainsString("ContentItem::query()->where('site_id', $site)", $controller);
        $this->assertStringNotContainsString('fake success', strtolower($controller));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "explorer-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        return $this->inTenant($membership, fn () => Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
            'last_sync_at' => now(),
        ]));
    }

    private function content(TenantMembership $membership, Site $site, int $remoteId, string $type, string $title): ContentItem
    {
        return $this->inTenant($membership, fn () => ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => $type,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'draft',
            'link' => "https://wordpress.example.test/?p={$remoteId}",
            'remote_hash' => hash('sha256', "explorer-{$remoteId}"),
            'remote_modified_at' => now(),
            'synced_at' => now(),
        ]));
    }

    private function taxonomy(TenantMembership $membership, Site $site, int $remoteId, string $taxonomy, string $name): TaxonomyTerm
    {
        return $this->inTenant($membership, fn () => TaxonomyTerm::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'taxonomy' => $taxonomy,
            'name' => $name,
            'slug' => strtolower($name),
            'usage_count' => 1,
            'synced_at' => now(),
        ]));
    }

    private function media(TenantMembership $membership, Site $site, int $remoteId, string $title): MediaItem
    {
        return $this->inTenant($membership, fn () => MediaItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'title' => $title,
            'slug' => strtolower($title),
            'mime_type' => 'image/jpeg',
            'media_type' => 'image',
            'source_url' => "https://wordpress.example.test/media/{$remoteId}.jpg",
            'synced_at' => now(),
        ]));
    }

    private function inTenant(TenantMembership $membership, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
