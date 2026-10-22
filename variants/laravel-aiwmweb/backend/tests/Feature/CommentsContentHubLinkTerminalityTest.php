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

class CommentsContentHubLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-COMM-A0D005681B';

    public function test_exact_canonical_operation_metadata_matches_comments_content_hub_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('comments', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/comments', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalCommentsWorkspace.razor', $operation['current_source']);
        $this->assertStringContainsString('/content', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_comments_source_is_real_guarded_site_bound_workspace(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/module/comments', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSiteBound',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame('content.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());
        $this->assertSame(['tenant'], $source->parameterNames());
    }

    public function test_authorized_user_reaches_comments_workspace_and_tenant_content_destination_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->withoutVite();

        $sitesBefore = Site::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/comments?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/content')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk();
        $this->assertSame('alpha', $context->json('tenant.slug'));
        $this->assertContains('content.view', $context->json('permissions'));
        $this->assertSame(
            "/api/v1/tenants/alpha/sites/{$site->id}/comments",
            $context->json('api.comments'),
        );

        $this->assertSame($sitesBefore, Site::query()->withoutGlobalScopes()->count());
    }

    public function test_runtime_binding_is_comments_only_and_derives_target_from_authoritative_tenant_context(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/comments-content-hub-link.tsx'));

        $this->assertStringContainsString("route.key === 'comments'", $app);
        $this->assertStringContainsString('<CommentsContentHubLink context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/content')", $control);
        $this->assertStringContainsString("context.permissions.includes('content.view')", $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('/tenants/beta/content', $control);
    }

    public function test_guest_missing_permission_and_cross_tenant_source_paths_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/module/comments')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->get('/tenants/limited/module/comments?site='.$limitedSite->id)
            ->assertForbidden();

        $alpha = User::factory()->create();
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $beta = User::factory()->create();
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->actingAs($alpha)
            ->get('/tenants/beta/module/comments?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alpha)
            ->get('/tenants/alpha/module/comments?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alpha)
            ->get('/tenants/alpha/module/comments?site='.$alphaSite->id)
            ->assertOk();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "comments-content-hub-{$slug}-{$user->id}"]);
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
