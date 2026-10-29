<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
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

final class ContentExplorerTagsTabTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-D6126926AD';

    public function test_source_contract_selects_tags_and_clears_selection_and_preview(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );

        $this->assertStringContainsString('@onclick="SetTagsTab"', $source);
        $this->assertStringContainsString('private void SetTagsTab() => SetTab("tags");', $source);
        $this->assertStringContainsString('_selected.Clear(); _preview = null;', $source);
    }

    public function test_laravel_tags_tab_is_bound_to_authoritative_post_tag_taxonomy(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-explorer-tags-tab-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentExplorerTagsTabControl context={context}', $app);
        $this->assertStringContainsString('taxonomy=post_tag', $control);
        $this->assertStringContainsString('requestedTaxonomyTypeFromLocation', $pages);
        $this->assertStringContainsString("route.key === 'taxonomy'", $pages);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_taxonomy_read_is_guarded_and_foreign_site_fails_closed(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'tags-alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($membership, 'Tags Alpha');

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/tags-alpha/sites/'.$site->id.'/taxonomy?taxonomy=post_tag', 'GET'),
        );
        $this->assertSame(ContentApiController::class.'@taxonomy', ltrim($route->getActionName(), '\\'));
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/tags-alpha/sites/'.$site->id.'/taxonomy?taxonomy=post_tag')
            ->assertOk();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'tags-beta', ['tenant.view', 'sites.view', 'content.view']);
        $betaSite = $this->site($beta, 'Tags Beta');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/tags-alpha/sites/'.$betaSite->id.'/taxonomy?taxonomy=post_tag')
            ->assertNotFound();
    }

    public function test_missing_content_view_permission_fails_closed(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'tags-limited', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Tags Limited');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/tags-limited/sites/'.$site->id.'/taxonomy?taxonomy=post_tag')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "tags-tab-{$slug}-{$user->id}"]);

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
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        } finally {
            $context->forget();
        }
    }
}
