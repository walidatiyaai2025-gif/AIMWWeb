<?php

namespace Tests\Feature;

use App\Models\ContentItem;
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

final class GlobalContentBrowserSessionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_posts_and_pages_use_web_session_before_auth_and_tenant_context(): void
    {
        foreach (['global-posts', 'global-pages'] as $resource) {
            foreach (['GET', 'POST'] as $method) {
                $path = '/api/v1/tenants/wordpress/'.$resource.($method === 'POST' ? '/trash' : '');
                $route = Route::getRoutes()->match(Request::create($path, $method));
                $middleware = $route->gatherMiddleware();

                $web = array_search('web', $middleware, true);
                $auth = array_search('auth', $middleware, true);
                $tenant = array_search('tenant.context', $middleware, true);

                $this->assertNotFalse($web, $path.' must start the browser session.');
                $this->assertNotFalse($auth, $path.' must require authentication.');
                $this->assertNotFalse($tenant, $path.' must enforce tenant isolation.');
                $this->assertLessThan($auth, $web, $path.' must initialize session before auth.');
                $this->assertLessThan($tenant, $auth, $path.' must authenticate before resolving tenant.');
            }
        }
    }

    public function test_login_session_reads_real_posts_and_pages_and_guest_or_foreign_tenant_cannot(): void
    {
        $tenant = Tenant::query()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
        $user = User::factory()->create(['email' => 'session-reader@example.test']);
        $this->grantContentRead($tenant, $user);

        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => 'WordPress Site',
            'url' => 'https://site.example.test',
            'status' => 'active',
        ]);
        foreach (['post', 'page'] as $type) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'type' => $type,
                'remote_id' => $type === 'post' ? 37 : 22,
                'title' => 'Actual '.ucfirst($type),
                'slug' => 'actual-'.$type,
                'body' => '<p>Authoritative WordPress data</p>',
                'status' => 'publish',
            ]);
        }
        app(TenantContext::class)->forget();

        $this->getJson('/api/v1/tenants/wordpress/global-posts')->assertUnauthorized();
        $this->getJson('/api/v1/tenants/wordpress/global-pages')->assertUnauthorized();

        $this->postJson('/api/login', [
            'email' => 'session-reader@example.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/v1/tenants/wordpress/global-posts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Actual Post');
        $this->getJson('/api/v1/tenants/wordpress/global-pages')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Actual Page');

        $this->getJson('/api/v1/tenants/foreign/global-posts')->assertNotFound();
        $this->getJson('/api/v1/tenants/foreign/global-pages')->assertNotFound();

        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/v1/tenants/wordpress/global-posts')->assertUnauthorized();
    }

    private function grantContentRead(Tenant $tenant, User $user): void
    {
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'session-reader-'.$user->id]);
        foreach (['tenant.view', 'sites.view', 'content.view'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
    }
}
