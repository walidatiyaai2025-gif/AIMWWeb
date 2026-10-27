<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class ContentHubWorkspaceUrlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-FE1F15935E';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_global_content_hub_workspace_url_semantics(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/content | /module/content', $operation['route_screen']);
        $this->assertSame('@WorkspaceUrl( -> @WorkspaceUrl(', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalContentHub.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_site_context_is_authoritatively_tenant_validated_before_workspace_links_render(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/content', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', $route->getActionName());
        $this->assertSame('content.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $siteCount = Site::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/content?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('tenant.slug', 'alpha');

        $this->assertSame($siteCount, Site::withoutGlobalScopes()->count());
    }

    public function test_guest_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $this->get('/tenants/alpha/content')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/content')->assertForbidden();

        $alpha = User::factory()->create();
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $beta = User::factory()->create();
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->actingAs($alpha)->get('/tenants/beta/content')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/alpha/context?site='.$betaSite->id)->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertOk();
    }

    public function test_runtime_helper_is_operation_linked_source_bounded_and_read_only(): void
    {
        $helper = (string) file_get_contents(resource_path('js/content-hub-workspace-url.ts'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $helper);
        $this->assertStringContainsString("'/module/sync'", $helper);
        $this->assertStringContainsString("'/module/posts'", $helper);
        $this->assertStringContainsString("'/module/pages'", $helper);
        $this->assertStringContainsString("'/module/media'", $helper);
        $this->assertStringContainsString("'/module/taxonomy'", $helper);
        $this->assertStringContainsString("new URLSearchParams(search).get('site')", $helper);
        $this->assertStringContainsString('tenantUrl(tenantSlug, path)', $helper);
        $this->assertStringNotContainsString('apiRequest', $helper);
        $this->assertStringNotContainsString('fetch(', $helper);

        $this->assertStringContainsString('contentHubWorkspaceUrl', $pages);
        $this->assertStringContainsString('isContentHubWorkspacePath', $pages);
        $this->assertStringContainsString('CONTENT_HUB_WORKSPACE_URL_OPERATION_ID', $pages);
        $this->assertStringContainsString("route.key === 'content-hub'", $pages);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "content-workspace-url-{$slug}-{$user->id}"]);
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
}
