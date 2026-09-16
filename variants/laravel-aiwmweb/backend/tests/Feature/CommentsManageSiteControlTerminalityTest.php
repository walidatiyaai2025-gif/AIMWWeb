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

class CommentsManageSiteControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-COMM-C083D47BC4';

    public function test_exact_canonical_input_is_the_pending_global_comments_manage_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('comments', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/comments', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalCommentsWorkspace.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_context_advertises_only_the_tenant_scoped_selected_site_and_matching_comments_api(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/context?site='.$site->id)
            ->assertOk();

        $response
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('active_site.name', 'Alpha Site')
            ->assertJsonPath('api.comments', '/api/v1/tenants/alpha/sites/'.$site->id.'/comments');

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'tenant_id' => $membership->tenant_id,
            'name' => 'Alpha Site',
        ]);
    }

    public function test_manage_destination_uses_existing_content_guarded_site_comments_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/17/comments', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirectSite',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('content.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/comments', $route->defaults['workspace_target'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['content.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->getJson('/tenants/limited/context?site='.$limitedSite->id)
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/comments')
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaMembership->tenant_id]);
    }

    public function test_authorized_manage_navigation_is_read_only_and_reaches_real_comments_workspace(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/comments')
            ->assertRedirect('/tenants/alpha/module/comments?site='.$site->id);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/comments?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after, 'Manage navigation must not mutate authoritative Site persistence.');
    }

    public function test_production_wiring_is_read_only_and_scoped_to_the_claimed_control(): void
    {
        $component = (string) file_get_contents(resource_path('js/comments-manage-site-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('active_site', $component);
        $this->assertStringContainsString('context.api.comments', $component);
        $this->assertStringContainsString("resolveCapability(context, commentsRoute).state !== 'enabled'", $component);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, `/sites/${siteId}/comments`)', $component);
        $this->assertStringContainsString('CommentsManageSiteControl', $app);
        $this->assertStringContainsString("route.key === 'comments'", $app);
        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('method:', $component);
        $this->assertStringNotContainsString('secret', strtolower($component));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "comments-manage-{$slug}-{$user->id}"]);
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
