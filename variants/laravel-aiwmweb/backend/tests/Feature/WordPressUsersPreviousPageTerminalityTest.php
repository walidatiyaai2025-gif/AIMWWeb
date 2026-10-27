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

final class WordPressUsersPreviousPageTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-B78216241F';

    public function test_source_and_laravel_frontend_bind_exact_previous_page_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $components = (string) file_get_contents(resource_path('js/components.tsx'));

        $this->assertStringContainsString('OnClick="PreviousPageClickedAsync"', $source);
        $this->assertStringContainsString('Disabled="@(_page <= 1 || _working)"', $source);
        $this->assertStringContainsString(
            'private async Task PreviousPageClickedAsync(MouseEventArgs _) { if (_page > 1) { _page--; await LoadAsync(); } }',
            $source,
        );

        $this->assertSame(1, substr_count($pages, self::OPERATION_ID));
        $this->assertStringContainsString(
            "'wp-users': { previous: '".self::OPERATION_ID."' }",
            $pages,
        );
        $this->assertStringContainsString('data-canonical-operation={previousOperationId}', $components);
        $this->assertStringContainsString('onClick={() => onPage(previousAppDataGridPage(page))}', $components);
        $this->assertStringContainsString('disabled={page <= 1}', $components);
    }

    public function test_previous_page_read_uses_authoritative_wordpress_get_with_requested_page(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['sites.view']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        Http::fake([
            'https://alpha.example.test/wp-json/wp/v2/users*' => Http::response([
                ['id' => 41, 'username' => 'page-two-user', 'email' => 'page2@example.test', 'roles' => ['editor']],
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/users?page=2")
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('data.0.id', 41);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://alpha.example.test/wp-json/wp/v2/users?')
                && ($query['page'] ?? null) === '2'
                && ($query['context'] ?? null) === 'edit'
                && ($query['orderby'] ?? null) === 'registered_date'
                && ($query['order'] ?? null) === 'desc';
        });
    }

    public function test_route_is_authenticated_tenant_scoped_and_foreign_tenant_or_site_fails_closed(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/users?page=2', 'GET'),
        );

        $this->assertSame(WordPressUsersController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.wordpress-users.index', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        Http::fake();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['sites.view']);
        $alphaSite = $this->site($alpha, 'https://alpha.example.test');
        $this->credential($alpha, $alphaSite);

        $this->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users?page=2")
            ->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membershipExisting($limited, $alpha->tenant, ['tenant.view'], 'limited');
        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users?page=2")
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['sites.view']);
        $betaSite = $this->site($beta, 'https://beta.example.test');
        $this->credential($beta, $betaSite);

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/beta/sites/{$betaSite->id}/users?page=2")
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/users?page=2")
            ->assertNotFound();

        Http::assertNothingSent();
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
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
