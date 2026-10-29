<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SettingsMySessionsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-A6EA54E66D';

    public function test_source_and_laravel_control_preserve_the_exact_settings_sessions_intent(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/settings-my-sessions-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('href="/settings/sessions"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("SETTINGS_MY_SESSIONS_SOURCE_PATH = '/settings/sessions'", $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, SETTINGS_MY_SESSIONS_SOURCE_PATH)', $control);
        $this->assertStringContainsString('<SettingsMySessionsLinkControl context={context} />', $app);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString("method: 'PATCH'", $control);
        $this->assertStringNotContainsString("method: 'DELETE'", $control);
    }

    public function test_destination_is_the_real_guarded_tenant_alias(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/sessions', 'GET'));

        $this->assertSame(CanonicalWorkspaceRouteController::class.'@redirect', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.alias.settings-sessions', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('sessions.manage,sessions.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/account/sessions', $route->defaults['workspace_target'] ?? null);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_alias_redirects_inside_the_active_tenant_and_rejects_foreign_or_unauthorized_access(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', ['tenant.view', 'sessions.manage', 'sessions.view']);
        [$beta] = $this->tenantWithUser('beta', ['tenant.view', 'sessions.manage', 'sessions.view']);
        [, $limited] = $this->tenantWithUser('limited', ['tenant.view']);

        $this->actingAs($authorized->user)
            ->get("/tenants/{$alpha->slug}/settings/sessions")
            ->assertRedirect("/tenants/{$alpha->slug}/account/sessions");

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/settings/sessions")
            ->assertNotFound();

        $this->actingAs($limited->user)
            ->get('/tenants/limited/settings/sessions')
            ->assertForbidden();
    }

    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => "{$slug}-settings-sessions@example.test"]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->setRelation('user', $user);

        $role = Role::query()->create(['name' => "settings-sessions-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $membership];
    }
}
