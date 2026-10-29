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

final class GlobalPostsApplyFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-7A726BAF5F';

    public function test_source_control_and_real_tenant_authorized_read_are_wired(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor'));
        $control = (string) file_get_contents(resource_path('js/global-posts-apply-filters-control.tsx'));
        $widget = (string) file_get_contents(resource_path('js/global-posts-confirm-trash-widget.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/GlobalPostsTrashController.php'));
        $service = (string) file_get_contents(app_path('Content/GlobalPostsTrashService.php'));

        $this->assertStringContainsString('@onclick="ApplyAsync"', $source);
        $this->assertStringContainsString('private async Task ApplyAsync()', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('globalPostsFilterUrl', $widget);
        $this->assertStringContainsString('GlobalPostsApplyFiltersControl', $widget);
        $this->assertStringContainsString('setSelected(new Set())', $widget);
        $this->assertStringContainsString('setFilterRevision', $widget);
        $this->assertStringContainsString("Site::query()->findOrFail((int) \$data['site_id'])", $controller);
        $this->assertStringContainsString("\$auth->authorize('content.view')", $controller);
        $this->assertStringContainsString("->where('site_id', (int) \$filters['site_id'])", $service);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/global-posts', 'GET'));
        $this->assertSame(GlobalPostsTrashController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_apply_filters_rereads_real_tenant_scoped_pages_and_respects_site_status_search_and_page_size(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-pages-filter', ['tenant.view', 'content.view']);
        $first = $this->site($alpha, 'First pages site');
        $second = $this->site($alpha, 'Second pages site');

        $this->seedGlobalPost($alpha, $first, 11, 'Needle one', 'publish');
        $this->seedGlobalPost($alpha, $first, 12, 'Needle two', 'draft');
        $this->seedGlobalPost($alpha, $first, 13, 'Other page', 'publish');
        $this->seedGlobalPost($alpha, $second, 14, 'Needle other site', 'publish');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-pages-filter', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Foreign pages site');
        $this->seedGlobalPost($beta, $betaSite, 15, 'Needle foreign page', 'publish');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-posts?search=Needle&site_id='.$first->id.'&status=publish&per_page=10&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('data.0.wordpress_id', 11)
            ->assertJsonPath('data.0.site_id', $first->id)
            ->assertJsonPath('data.0.status', 'publish');

        $this->assertArrayNotHasKey('tenant_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('body', $response->json('data.0'));

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-posts?search=Needle&status=draft&per_page=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 12);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-posts?search=none&per_page=25')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);

        // The source Pages Explorer searches locally synchronized content and excerpts as well as title/slug.
        $this->seedGlobalPost($alpha, $first, 16, 'Neutral body search page', 'publish', 'unique-body-needle');
        $this->seedGlobalPost($alpha, $first, 17, 'Neutral excerpt search page', 'publish', '', 'unique-excerpt-needle');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-posts?search=unique-body-needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 16);
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-posts?search=unique-excerpt-needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 17);

        $this->assertSame('publish', $this->postStatus($alpha, 11));
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_direct_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha-pages-guard', ['content.view', 'tenant.view']);
        $alphaSite = $this->site($alpha, 'Alpha guard site');
        $this->seedGlobalPost($alpha, $alphaSite, 21, 'Visible page', 'draft');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-pages-guard', ['content.view', 'tenant.view']);
        $foreignSite = $this->site($beta, 'Beta guard site');
        $this->seedGlobalPost($beta, $foreignSite, 22, 'Secret page', 'publish');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['tenant.view'], 'viewer');

        $this->getJson('/api/v1/tenants/alpha-pages-guard/global-posts?site_id='.$alphaSite->id)
            ->assertUnauthorized();
        $this->actingAs($viewer)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-posts?site_id='.$alphaSite->id)
            ->assertForbidden();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/beta-pages-guard/global-posts?site_id='.$foreignSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-posts?site_id='.$foreignSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-posts?site_id=-1')
            ->assertUnprocessable();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-posts?per_page=1000')
            ->assertUnprocessable();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug);
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'global-post-filter-'.$roleName.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function seedGlobalPost(TenantMembership $membership, Site $site, int $remoteId, string $title, string $status, string $body = '', string $excerpt = ''): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'body' => $body,
            'excerpt' => $excerpt,
            'status' => $status,
            'remote_modified_at' => now(),
        ]);
        app(TenantContext::class)->forget();
    }

    private function postStatus(TenantMembership $membership, int $remoteId): string
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $status = (string) ContentItem::query()->where('remote_id', $remoteId)->firstOrFail()->status;
        app(TenantContext::class)->forget();

        return $status;
    }
}
