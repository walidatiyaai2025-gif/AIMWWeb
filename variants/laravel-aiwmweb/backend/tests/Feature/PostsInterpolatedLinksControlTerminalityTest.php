<?php

namespace Tests\Feature;

use App\Http\Controllers\GlobalPostsTrashController;
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

final class PostsInterpolatedLinksControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-73E4F88038';

    public function test_exact_canonical_operation_matches_global_posts_dynamic_links(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/posts', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_global_posts_read_returns_authoritative_site_and_wordpress_identifiers(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->content($membership, $site, 71);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/global-posts', 'GET'));
        $this->assertSame(GlobalPostsTrashController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/global-posts?site_id=999&wordpress_id=999')
            ->assertOk()
            ->assertJsonPath('data.0.site_id', $site->id)
            ->assertJsonPath('data.0.site_name', 'Alpha Site')
            ->assertJsonPath('data.0.wordpress_id', 71)
            ->assertJsonMissing(['site_id' => 999])
            ->assertJsonMissing(['wordpress_id' => 999]);
    }

    public function test_workspace_permission_and_cross_tenant_boundaries_fail_closed(): void
    {
        $this->withoutVite();

        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $this->content($alphaMembership, $alphaSite, 71);

        $this->get('/tenants/alpha/module/posts?site='.$alphaSite->id)->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->get('/tenants/limited/module/posts?site='.$limitedSite->id)
            ->assertForbidden();
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/global-posts')
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->content($betaMembership, $betaSite, 88);

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/module/posts?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/beta/global-posts')
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/module/posts?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha/global-posts')
            ->assertOk()
            ->assertJsonPath('data.0.site_id', $alphaSite->id)
            ->assertJsonPath('data.0.wordpress_id', 71)
            ->assertJsonMissing(['site_id' => $betaSite->id])
            ->assertJsonMissing(['wordpress_id' => 88]);
    }

    public function test_production_wiring_is_global_posts_only_tenant_bound_and_read_only(): void
    {
        $widget = (string) file_get_contents(resource_path('js/global-posts-confirm-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/posts-interpolated-links-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('<PostsInterpolatedLinksControl', $widget);
        $this->assertStringContainsString('tenantSlug={context.tenant.slug}', $widget);
        $this->assertStringContainsString('siteId={row.site_id}', $widget);
        $this->assertStringContainsString('wordpressId={row.wordpress_id}', $widget);
        $this->assertStringContainsString('siteName={row.site_name}', $widget);
        $this->assertStringContainsString('/content/post/', $control);
        $this->assertStringContainsString('/explorer?site=', $control);
        $this->assertStringContainsString('normalizeAuthoritativeSiteId', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('axios', $control);
        $this->assertStringNotContainsString('AIMW-CONT-', str_replace(self::OPERATION_ID, '', $control));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "posts-links-{$slug}-{$user->id}"]);

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
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function content(TenantMembership $membership, Site $site, int $remoteId): ContentItem
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'type' => 'post',
            'remote_id' => $remoteId,
            'title' => "Post {$remoteId}",
            'slug' => "post-{$remoteId}",
            'status' => 'publish',
            'link' => "https://example.test/post-{$remoteId}",
            'remote_hash' => hash('sha256', (string) $remoteId),
            'synced_at' => now(),
        ]);
        $context->forget();

        return $item;
    }
}
