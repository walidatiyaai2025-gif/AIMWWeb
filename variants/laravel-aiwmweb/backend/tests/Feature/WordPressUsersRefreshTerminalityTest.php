<?php

namespace Tests\Feature;

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

final class WordPressUsersRefreshTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-5A397204EF';

    public function test_source_refresh_clicked_contract_is_wired_to_existing_native_wordpress_users_read(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/sites/{SiteId:guid}/users', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/UsersManager.razor', $row['current_source']);
        $this->assertSame('@L[ [RefreshClickedAsync]', $row['visible_control']);
        $this->assertFalse($row['mutation']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'));
        $this->assertStringContainsString('OnClick="RefreshClickedAsync"', $source);
        $this->assertStringContainsString('private Task RefreshClickedAsync(MouseEventArgs _) => LoadAsync();', $source);
        $this->assertStringContainsString('UsersService.GetAsync(SiteId, _search, _role, _page)', $source);

        $frontend = (string) file_get_contents(resource_path('js/wordpress-users-refresh-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-canonical-operation={WORDPRESS_USERS_REFRESH_OPERATION_ID}', $frontend);
        $this->assertStringContainsString("context.api['wp-users'] !== expected || endpoint !== expected", $frontend);
        $this->assertStringContainsString('<WordPressUsersRefreshControl', $pages);
        $this->assertStringContainsString('const refreshed = await query.refetch();', $pages);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/users', 'GET'));
        $this->assertSame(WordPressUsersController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.wordpress-users.index', $route->getName());
        foreach (['web', 'auth', 'tenant.context'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    public function test_refresh_reissues_authoritative_wordpress_rest_get_with_filters_and_no_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        $reads = 0;
        Http::fake(function ($request) use (&$reads) {
            $reads++;

            return Http::response([[
                'id' => $reads === 1 ? 71 : 72,
                'username' => $reads === 1 ? 'before' : 'after',
                'name' => 'WordPress user',
            ]], 200);
        });

        $url = "/api/v1/tenants/alpha/sites/{$site->id}/users?page=2&search=Alice";
        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', 71)
            ->assertJsonPath('current_page', 2);
        $this->actingAs($user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', 72)
            ->assertJsonPath('current_page', 2);

        $this->assertSame(2, $reads);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://alpha.example.test/wp-json/wp/v2/users?')
            && str_contains($request->url(), 'search=Alice')
            && str_contains($request->url(), 'page=2')
        );
    }

    public function test_guest_missing_permission_and_foreign_tenant_site_cannot_fetch_wordpress_users(): void
    {
        Http::fake();

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'https://alpha.example.test');
        $this->credential($alpha, $alphaSite);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'https://beta.example.test');
        $this->credential($beta, $betaSite);

        $this->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users")->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membershipExisting($limited, $alpha->tenant, ['tenant.view'], 'limited');
        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users")
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/users")
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta/sites/{$betaSite->id}/users")
            ->assertNotFound();

        Http::assertNothingSent();
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug);
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $name): TenantMembership
    {
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $name.'-'.$user->id]);
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
            'name' => 'WordPress users',
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
