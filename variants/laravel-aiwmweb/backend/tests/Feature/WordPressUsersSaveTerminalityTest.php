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

final class WordPressUsersSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-D307963B59';

    public function test_source_route_and_action_contract_bind_exact_save_async_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'),
        );

        foreach ([
            'OnValidSubmit="SaveAsync"',
            'CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit);',
            '? await UsersService.UpdateAsync(SiteId, _editingId, _model)',
            ': await UsersService.CreateAsync(SiteId, _model);',
            'if (result.Success) { ResetForm(); await LoadAsync(); }',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/users/save', 'POST'),
        );

        $this->assertSame(WordPressUsersController::class.'@save', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.wordpress-users.save', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $actions = config('frontend_actions');
        $contract = $actions['wordpress-users.save'] ?? null;
        $this->assertIsArray($contract);
        $this->assertSame(self::OPERATION_ID, $contract['operation_id']);
        $this->assertSame('content.edit', $contract['permission']);
        $this->assertSame('site', $contract['ownership']);
        $this->assertSame('users.read', $contract['connector_scope']);
        $this->assertSame('/api/v1/tenants/{tenant}/sites/{site}/users/save', $contract['endpoint']);
        $this->assertSame('wp-users', $contract['reconcile_api_key']);
        $this->assertTrue($contract['terminal_candidate']);
    }

    public function test_create_mutates_wordpress_then_authoritatively_rereads_before_success(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['sites.view', 'content.view', 'content.edit']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        Http::fake([
            'https://alpha.example.test/wp-json/wp/v2/users' => Http::response([
                'id' => 77,
                'username' => 'new-user',
                'email' => 'new@example.test',
                'roles' => ['editor'],
            ], 201),
            'https://alpha.example.test/wp-json/wp/v2/users/77?context=edit' => Http::response([
                'id' => 77,
                'username' => 'new-user',
                'name' => 'New User',
                'email' => 'new@example.test',
                'roles' => ['editor'],
            ], 200),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/alpha/sites/{$site->id}/users/save", [
                'username' => 'new-user',
                'email' => 'new@example.test',
                'password' => 'secret-123',
                'name' => 'New User',
                'first_name' => 'New',
                'last_name' => 'User',
                'url' => 'https://example.test',
                'description' => 'Editor account',
                'role' => 'editor',
            ])
            ->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mode', 'create')
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('data.id', 77)
            ->assertJsonPath('data.email', 'new@example.test');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users'
            && ($request->data()['username'] ?? null) === 'new-user'
            && ($request->data()['password'] ?? null) === 'secret-123'
            && ($request->data()['roles'] ?? null) === ['editor']
        );
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users/77?context=edit'
        );
    }

    public function test_update_requires_existing_remote_user_omits_blank_password_and_rereads_after_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['sites.view', 'content.view', 'content.edit']);
        $site = $this->site($membership, 'https://alpha.example.test');
        $this->credential($membership, $site);

        $reads = 0;
        Http::fake(function ($request) use (&$reads) {
            if ($request->method() === 'GET'
                && $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users/55?context=edit') {
                $reads++;
                return Http::response([
                    'id' => 55,
                    'username' => 'existing',
                    'name' => $reads === 1 ? 'Before' : 'After',
                    'email' => 'existing@example.test',
                    'roles' => ['author'],
                ], 200);
            }

            if ($request->method() === 'POST'
                && $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users/55') {
                return Http::response([
                    'id' => 55,
                    'name' => 'After',
                ], 200);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/alpha/sites/{$site->id}/users/save", [
                'wordpress_user_id' => 55,
                'email' => 'existing@example.test',
                'password' => '',
                'name' => 'After',
                'first_name' => '',
                'last_name' => '',
                'url' => '',
                'description' => '',
                'role' => 'author',
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'update')
            ->assertJsonPath('reconciled', true)
            ->assertJsonPath('data.id', 55)
            ->assertJsonPath('data.name', 'After');

        $this->assertSame(2, $reads);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST'
            && $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users/55'
            && ! array_key_exists('password', $request->data())
            && ($request->data()['roles'] ?? null) === ['author']
        );
    }

    public function test_guest_missing_permission_foreign_tenant_and_caller_identity_fail_closed_before_wordpress(): void
    {
        Http::fake();

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['sites.view', 'content.view', 'content.edit']);
        $alphaSite = $this->site($alpha, 'https://alpha.example.test');
        $this->credential($alpha, $alphaSite);

        $payload = [
            'username' => 'new-user',
            'email' => 'new@example.test',
            'password' => 'secret-123',
            'role' => 'subscriber',
        ];

        $this->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users/save", $payload)
            ->assertUnauthorized();

        $viewer = User::factory()->create();
        $viewerMembership = $this->membershipExisting($viewer, $alpha->tenant, ['sites.view', 'content.view'], 'viewer');
        $this->actingAs($viewer)
            ->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users/save", $payload)
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['sites.view', 'content.view', 'content.edit']);
        $betaSite = $this->site($beta, 'https://beta.example.test');
        $this->credential($beta, $betaSite);

        $this->actingAs($editor)
            ->postJson("/api/v1/tenants/beta/sites/{$betaSite->id}/users/save", $payload)
            ->assertNotFound();

        $this->actingAs($editor)
            ->postJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/users/save", $payload)
            ->assertNotFound();

        $this->actingAs($editor)
            ->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/users/save", $payload + [
                'tenant_id' => 999,
                'site_id' => 999,
            ])
            ->assertUnprocessable();

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
