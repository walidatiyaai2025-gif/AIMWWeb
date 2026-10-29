<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WordPressUsersSearchTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-E6888B0B4D';

    public function test_source_and_laravel_bind_exact_search_async_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalUsersWorkspace.razor'),
        );
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/WordPressUsersController.php'));

        $this->assertStringContainsString('OnClick="SearchAsync"', $source);
        $this->assertStringContainsString('private async Task SearchAsync(MouseEventArgs _) { _page = 1; await LoadAsync(); }', $source);
        $this->assertStringContainsString('UsersService.GetAsync(siteId, search, role, page, 50)', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('WORDPRESS_USERS_SEARCH_OPERATION_ID', $frontend);
        $this->assertStringContainsString("route.key === 'wp-users'", $frontend);
        $this->assertStringContainsString('setPage(1); setSearch(searchInput);', $frontend);
        $this->assertStringContainsString('endpointWithQuery(endpoint!, page, search, { ...taxonomyAppliedQuery, ...wordpressUsersFilters })', $frontend);

        $this->assertStringContainsString("\$search = trim((string) \$request->query('search', ''));", $controller);
        $this->assertStringContainsString("\$query['search'] = \$search;", $controller);
        $this->assertStringContainsString("\$items = \$this->wordpress->list(\$site, 'users', \$query);", $controller);
    }

    public function test_search_reads_wordpress_users_for_only_the_current_tenant_owned_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        Http::fake([
            'https://alpha.example.test/wp-json/wp/v2/users*' => Http::response([
                [
                    'id' => 77,
                    'username' => 'needle',
                    'name' => 'Needle User',
                    'email' => 'needle@example.test',
                    'roles' => ['editor'],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/users?search=needle&page=1")
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 77)
            ->assertJsonPath('data.0.username', 'needle');

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'GET') {
                return false;
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://alpha.example.test/wp-json/wp/v2/users?')
                && ($query['context'] ?? null) === 'edit'
                && (int) ($query['page'] ?? 0) === 1
                && (int) ($query['per_page'] ?? 0) === 50
                && ($query['search'] ?? null) === 'needle';
        });

        $foreignUser = User::factory()->create();
        $foreignMembership = $this->membership($foreignUser, 'foreign', ['tenant.view', 'sites.view']);
        $foreignSite = $this->site($foreignMembership, 'https://foreign.example.test');

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/foreign/sites/{$foreignSite->id}/users?search=needle&page=1")
            ->assertNotFound();

        Http::assertSentCount(1);
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "wordpress-users-search-{$slug}-{$user->id}"]);

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
            'name' => 'WordPress Users Search',
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
