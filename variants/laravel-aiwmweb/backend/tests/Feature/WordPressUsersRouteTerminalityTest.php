<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\WordPressUsersController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WordPressUsersRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-1C2443112C';

    public function test_exact_canonical_operation_is_the_site_wordpress_users_route(): void
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
        $this->assertSame('/sites/{SiteId:guid}/users', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/UsersManager.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertTrue((bool) $operation['native_wp_rest']);
        $this->assertFalse((bool) $operation['connector_required']);
    }

    public function test_site_route_is_explicit_guarded_operation_bound_and_read_only(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/123/users', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirectSite',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.site.wordpress-users', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view,sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/users', $route->defaults['workspace_target'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('[0-9]+', $route->wheres['site'] ?? null);
    }

    public function test_authorized_site_route_binds_owned_site_and_opens_real_users_workspace_contract(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->withoutVite();

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/users")
            ->assertRedirect("/tenants/alpha/module/users?site={$site->id}")
            ->assertSessionHas('canonical_site_id', $site->id);

        $this->actingAs($user)
            ->get("/tenants/alpha/module/users?site={$site->id}")
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson("/tenants/alpha/context?site={$site->id}")
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath(
                'api.wp-users',
                "/api/v1/tenants/alpha/sites/{$site->id}/users",
            );
    }

    public function test_users_api_reads_authoritative_wordpress_state_for_the_owned_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        Http::fake([
            'https://alpha.example.test/wp-json/wp/v2/users*' => Http::response([
                [
                    'id' => 41,
                    'username' => 'editor-one',
                    'name' => 'Editor One',
                    'email' => 'editor@example.test',
                    'roles' => ['editor'],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/users?page=1&per_page=50&search=editor&role=editor")
            ->assertOk()
            ->assertJsonPath('data.0.id', 41)
            ->assertJsonPath('data.0.username', 'editor-one')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'GET') {
                return false;
            }

            $url = $request->url();
            if (! str_starts_with($url, 'https://alpha.example.test/wp-json/wp/v2/users?')) {
                return false;
            }

            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return ($query['page'] ?? null) === '1'
                && ($query['per_page'] ?? null) === '50'
                && ($query['context'] ?? null) === 'edit'
                && ($query['search'] ?? null) === 'editor'
                && ($query['roles'] ?? null) === 'editor';
        });
    }

    public function test_empty_and_failure_states_are_truthful_and_not_synthesized(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'),
        );
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('@page "/sites/{SiteId:guid}/users"', $source);
        $this->assertStringContainsString('Loading WordPress users and roles.', $source);
        $this->assertStringContainsString('CouldNotLoadUsers', $source);
        $this->assertStringContainsString('NoUsers', $source);

        $this->assertStringContainsString("r('wp-users', '/module/users'", $core);
        $this->assertStringContainsString("permission: 'sites.view'", $core);
        $this->assertStringContainsString("if (route.key === 'wp-users')", $app);
        $this->assertStringContainsString('<WorkspacePage context={context} route={route} />', $app);

        $this->assertStringContainsString('if (query.isLoading) return <LoadingState />;', $pages);
        $this->assertStringContainsString('if (query.error) return (', $pages);
        $this->assertStringContainsString('Data could not be loaded', $pages);
        $this->assertStringContainsString('No sample rows are synthesized when the server returns an empty result.', $pages);
    }

    public function test_missing_permission_and_foreign_tenant_site_fail_closed_before_wordpress_access(): void
    {
        Http::fake();

        $viewer = User::factory()->create();
        $alpha = $this->membership($viewer, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'https://alpha.example.test');

        $limited = User::factory()->create();
        $this->membershipExisting($limited, $alpha->tenant, ['tenant.view'], 'limited');

        $this->actingAs($limited)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/users")
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'https://beta.example.test');

        $this->actingAs($viewer)
            ->get("/tenants/alpha/sites/{$betaSite->id}/users")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->get("/tenants/beta/sites/{$betaSite->id}/users")
            ->assertNotFound();

        $this->actingAs($viewer)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/users")
            ->assertNotFound();

        Http::assertNothingSent();
        app(TenantContext::class)->forget();
    }

    public function test_existing_users_save_mutation_remains_a_separate_canonical_operation(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/123/users/save', 'POST'),
        );

        $this->assertSame(WordPressUsersController::class.'@save', ltrim($route->getActionName(), '\\'));
        $this->assertSame('AIMW-BILL-D307963B59', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
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

    private function site(TenantMembership $membership, string $url): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'WordPress Users',
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function credential(TenantMembership $membership, Site $site): SiteCredential
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $credential = SiteCredential::query()->create([
            'site_id' => $site->id,
            'username' => 'wp-admin',
            'secret_value' => 'application-password',
        ]);
        app(TenantContext::class)->forget();

        return $credential;
    }
}
