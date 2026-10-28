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

final class GlobalUsersRefreshTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-7C77CF7F7A';

    public function test_source_refresh_action_is_wired_to_authorized_live_wordpress_refetch(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalUsersWorkspace.razor'));
        $control = (string) file_get_contents(resource_path('js/global-users-refresh-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/WordPressUsersController.php'));

        $this->assertStringContainsString('OnClick="RefreshAsync"', $source);
        $this->assertStringContainsString('private Task RefreshAsync(MouseEventArgs _) => LoadAsync();', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation', $control);
        $this->assertStringContainsString('authorizedWordPressUsersRefreshEndpoint', $control);
        $this->assertStringContainsString('onRefreshRequested={async (trustedEndpoint)', $pages);
        $this->assertStringContainsString('const refreshed = await query.refetch()', $pages);
        $this->assertStringContainsString('wordpressUsersFilters', $pages);
        $this->assertStringContainsString("Site::query()->findOrFail(\$site)", $controller);
        $this->assertStringContainsString("\$query['roles'] = \$role;", $controller);
    }

    public function test_refresh_rereads_actual_filtered_remote_users_for_owned_site_without_mutating(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'global-users-refresh-alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($member, 'https://global-users-alpha.example.test');
        $this->credential($member, $site);

        Http::fake([
            'https://global-users-alpha.example.test/wp-json/wp/v2/users*' => Http::response([[
                'id' => 91, 'username' => 'editor-one', 'email' => 'editor@example.test',
                'roles' => ['editor'], 'name' => 'Editor One',
            ]], 200),
        ]);

        $url = "/api/v1/tenants/global-users-refresh-alpha/sites/{$site->id}/users?search=editor&role=editor&page=2";
        $this->actingAs($user)->getJson($url)
            ->assertOk()->assertJsonPath('data.0.id', 91)->assertJsonPath('current_page', 2);
        $this->actingAs($user)->getJson($url)
            ->assertOk()->assertJsonPath('data.0.id', 91);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return $request->method() === 'GET'
                && ($query['search'] ?? null) === 'editor'
                && ($query['roles'] ?? null) === 'editor'
                && (int) ($query['page'] ?? 0) === 2;
        });
    }

    public function test_guest_missing_permission_and_foreign_tenant_site_fail_closed(): void
    {
        $limited = User::factory()->create();
        $membership = $this->membership($limited, 'global-users-refresh-limited', ['tenant.view']);
        $site = $this->site($membership, 'https://global-users-limited.example.test');
        $url = "/api/v1/tenants/global-users-refresh-limited/sites/{$site->id}/users";
        $this->actingAs($limited)->getJson($url)->assertForbidden(); // 403
        auth()->logout();
        $this->getJson($url)->assertUnauthorized(); // 401

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'global-users-refresh-owner', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'https://global-users-owner.example.test');
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/global-users-refresh-owner/sites/{$site->id}/users")
            ->assertNotFound(); // 404
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/global-users-refresh-limited/sites/{$site->id}/users")
            ->assertNotFound();
        $this->assertNotSame($site->id, $alphaSite->id);
        Http::assertNothingSent();
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
