<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentExplorerReadController;
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

final class ContentExplorerContentTabTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-93B6E02ACF';

    public function test_source_contract_clears_selection_and_preview_when_content_tab_is_selected(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));

        $this->assertStringContainsString('@onclick="SetContentTab"', $source);
        $this->assertStringContainsString('private void SetContentTab() => SetTab("content");', $source);
        $this->assertStringContainsString('_selected.Clear(); _preview = null;', $source);
    }

    public function test_laravel_content_tab_is_bound_to_authoritative_explorer_and_local_reset_consumers(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $bulk = (string) file_get_contents(resource_path('js/content-explorer-bulk-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-explorer-content-tab-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentExplorerContentTabControl context={context}', $app);
        $this->assertStringContainsString("route.key === 'explorer'", $app);
        $this->assertStringContainsString('CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT', $pages);
        $this->assertStringContainsString('setExplorerPreview(null)', $pages);
        $this->assertStringContainsString('CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT', $bulk);
        $this->assertStringContainsString('setSelected(new Set())', $bulk);
        $this->assertStringContainsString('window.dispatchEvent(new Event(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT))', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('AIMW-CONT-9BA2C255AF', $control);
    }

    public function test_authoritative_explorer_read_is_guarded_and_tenant_site_scoped(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'content-tab-alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Content Tab Alpha');

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/content-tab-alpha/sites/'.$site->id.'/explorer', 'GET'),
        );
        $this->assertSame(ContentExplorerReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->get('/tenants/content-tab-alpha/explorer?site='.$site->id)
            ->assertOk();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/content-tab-alpha/sites/'.$site->id.'/explorer')
            ->assertOk();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'content-tab-beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Content Tab Beta');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/content-tab-alpha/sites/'.$betaSite->id.'/explorer')
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/tenants/content-tab-beta/explorer?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_missing_sites_permission_fails_closed_before_content_tab_runtime(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'content-tab-limited', ['tenant.view']);
        $site = $this->site($membership, 'Content Tab Limited');

        $this->actingAs($user)
            ->get('/tenants/content-tab-limited/explorer?site='.$site->id)
            ->assertForbidden();
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/content-tab-limited/sites/'.$site->id.'/explorer')
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "content-tab-{$slug}-{$user->id}"]);

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
