<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteSettingsReadController;
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

/** Canonical route-closure acceptance for AIMW-CONT-3443C173C6. */
final class SiteSettingsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-3443C173C6';

    public function test_exact_canonical_operation_matches_site_settings_open_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/settings', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_existing_get_route_is_guarded_read_only_and_keeps_visible_control_identity_separate(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/7/settings', 'GET'),
        );

        $this->assertSame('canonical.site.settings', $route->getName());
        $this->assertSame(SiteSettingsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('AIMW-SITE-9F9F2977B5', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_authorized_route_renders_authoritative_persisted_site_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Settings', 'https://alpha-settings.test');

        $before = Site::query()
            ->withoutGlobalScopes()
            ->findOrFail($site->id)
            ->only(['name', 'url', 'status', 'connection_status']);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('Alpha Settings')
            ->assertSee('https://alpha-settings.test')
            ->assertSee('active')
            ->assertSee('unknown');

        $after = Site::query()
            ->withoutGlobalScopes()
            ->findOrFail($site->id)
            ->only(['name', 'url', 'status', 'connection_status']);

        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest Tenant', 'slug' => 'guest']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites/1/settings')->assertRedirect();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.test');
        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/settings')
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view']);
        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta Site', 'https://beta.test');

        $this->actingAs($owner)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/settings')
            ->assertOk();

        $this->actingAs($owner)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/settings')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/tenants/beta/sites/'.$betaSite->id.'/settings')
            ->assertNotFound();

        $this->actingAs($owner)
            ->get('/tenants/alpha/sites/999999/settings')
            ->assertNotFound();
    }

    public function test_source_and_laravel_rendering_states_are_deterministic_and_not_fake(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/SiteSettingsReadController.php'));

        $this->assertStringContainsString('@page "/sites/{Id:guid}/settings"', $source);
        $this->assertStringContainsString('if (_loading)', $source);
        $this->assertStringContainsString('else if (_site is null)', $source);
        $this->assertStringContainsString('Site settings could not be loaded. Try again.', $source);
        $this->assertStringContainsString('SiteService.GetSiteSettingsAsync(Id)', $source);

        $this->assertStringContainsString('Authoritative settings summary', $view);
        $this->assertStringContainsString('{{ $site->name }}', $view);
        $this->assertStringContainsString('{{ $site->url }}', $view);
        $this->assertStringContainsString('where(\'tenant_id\', $activeTenant->getKey())', $controller);
        $this->assertStringContainsString('->firstOrFail()', $controller);
        $this->assertStringNotContainsString('fake success', strtolower($view));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-settings-route-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'unknown',
        ]);
        $context->forget();

        return $site;
    }
}
