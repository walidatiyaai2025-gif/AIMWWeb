<?php

namespace Tests\Feature;

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
use Tests\TestCase;

final class GlobalPagesApplyFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-8409FB077A';

    public function test_source_control_and_real_tenant_authorized_read_are_wired(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor'));
        $control = (string) file_get_contents(resource_path('js/global-pages-apply-filters-control.tsx'));
        $widget = (string) file_get_contents(resource_path('js/global-pages-confirm-trash-widget.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/GlobalPagesTrashController.php'));
        $service = (string) file_get_contents(app_path('Content/GlobalPagesTrashService.php'));

        $this->assertStringContainsString('@onclick="ApplyAsync"', $source);
        $this->assertStringContainsString('private async Task ApplyAsync()', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('globalPagesFilterUrl', $widget);
        $this->assertStringContainsString('GlobalPagesApplyFiltersControl', $widget);
        $this->assertStringContainsString('setSelected(new Set())', $widget);
        $this->assertStringContainsString('setFilterRevision', $widget);
        $this->assertStringContainsString("Site::query()->findOrFail((int) \$data['site_id'])", $controller);
        $this->assertStringContainsString("\$auth->authorize('content.view')", $controller);
        $this->assertStringContainsString("->where('site_id', (int) \$filters['site_id'])", $service);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/global-pages', 'GET'));
        $this->assertSame(GlobalPagesTrashController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_apply_filters_rereads_real_tenant_scoped_pages_and_respects_site_status_search_and_page_size(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-pages-filter', ['tenant.view', 'content.view']);
        $first = $this->site($alpha, 'First pages site');
        $second = $this->site($alpha, 'Second pages site');

        $this->page($alpha, $first, 11, 'Needle one', 'publish');
        $this->page($alpha, $first, 12, 'Needle two', 'draft');
        $this->page($alpha, $first, 13, 'Other page', 'publish');
        $this->page($alpha, $second, 14, 'Needle other site', 'publish');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-pages-filter', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Foreign pages site');
        $this->page($beta, $betaSite, 15, 'Needle foreign page', 'publish');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-pages?search=Needle&site_id='.$first->id.'&status=publish&per_page=10&page=1')
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
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-pages?search=Needle&status=draft&per_page=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 12);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-pages?search=none&per_page=25')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);

        // The source Pages Explorer searches locally synchronized content and excerpts as well as title/slug.
        $this->page($alpha, $first, 16, 'Neutral body search page', 'publish', 'unique-body-needle');
        $this->page($alpha, $first, 17, 'Neutral excerpt search page', 'publish', '', 'unique-excerpt-needle');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-pages?search=unique-body-needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 16);
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-pages-filter/global-pages?search=unique-excerpt-needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wordpress_id', 17);

        $this->assertSame('publish', $this->pageStatus($alpha, 11));
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_direct_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha-pages-guard', ['content.view', 'tenant.view']);
        $alphaSite = $this->site($alpha, 'Alpha guard site');
        $this->page($alpha, $alphaSite, 21, 'Visible page', 'draft');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-pages-guard', ['content.view', 'tenant.view']);
        $foreignSite = $this->site($beta, 'Beta guard site');
        $this->page($beta, $foreignSite, 22, 'Secret page', 'publish');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['tenant.view'], 'viewer');

        $this->getJson('/api/v1/tenants/alpha-pages-guard/global-pages?site_id='.$alphaSite->id)
            ->assertUnauthorized();
        $this->actingAs($viewer)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-pages?site_id='.$alphaSite->id)
            ->assertForbidden();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/beta-pages-guard/global-pages?site_id='.$foreignSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-pages?site_id='.$foreignSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-pages?site_id=-1')
            ->assertUnprocessable();
        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha-pages-guard/global-pages?per_page=1000')
            ->assertUnprocessable();
    }

    public function test_authoritative_page_navigation_handles_63_real_pages_and_is_tenant_safe(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-page-pagination', ['tenant.view', 'content.view']);
        $site = $this->site($alpha, 'Alpha many pages');

        for ($remoteId = 101; $remoteId <= 163; $remoteId++) {
            $this->page($alpha, $site, $remoteId, 'Indexed page '.$remoteId, 'publish');
        }

        $foreignUser = User::factory()->create();
        $beta = $this->membership($foreignUser, 'beta-page-pagination', ['tenant.view', 'content.view']);
        $foreignSite = $this->site($beta, 'Beta private pages');
        $this->page($beta, $foreignSite, 900, 'Foreign private title', 'publish');

        $first = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 63)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 3)
            ->assertJsonPath('per_page', 25)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.wordpress_id', 163);
        $this->assertStringNotContainsString('Foreign private title', $first->getContent());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=2&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 63)
            ->assertJsonPath('current_page', 2)
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.wordpress_id', 138);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=1000000&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 63)
            ->assertJsonPath('current_page', 3)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(13, 'data')
            ->assertJsonPath('data.0.wordpress_id', 113);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=4&search=not-found&per_page=25')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?site_id='.$foreignSite->id.'&page=1')
            ->assertNotFound();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=1000001')
            ->assertUnprocessable();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-page-pagination/global-pages?page=0')
            ->assertUnprocessable();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/beta-page-pagination/global-pages?page=1')
            ->assertNotFound();
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
        $role = Role::query()->create(['name' => 'global-page-filter-'.$roleName.'-'.$user->id]);
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

    private function page(TenantMembership $membership, Site $site, int $remoteId, string $title, string $status, string $body = '', string $excerpt = ''): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'page',
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'body' => $body,
            'excerpt' => $excerpt,
            'status' => $status,
            'remote_modified_at' => now(),
        ]);
        app(TenantContext::class)->forget();
    }

    private function pageStatus(TenantMembership $membership, int $remoteId): string
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $status = (string) ContentItem::query()->where('remote_id', $remoteId)->firstOrFail()->status;
        app(TenantContext::class)->forget();

        return $status;
    }
}
