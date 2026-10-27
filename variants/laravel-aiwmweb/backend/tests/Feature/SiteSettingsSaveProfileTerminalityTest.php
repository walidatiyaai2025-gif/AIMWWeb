<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteSettingsSaveProfileController;
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

final class SiteSettingsSaveProfileTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5C15471E4B';

    public function test_route_and_visible_control_are_bound_to_the_exact_canonical_operation(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/1/settings/profile', 'POST'),
        );

        $this->assertSame(
            SiteSettingsSaveProfileController::class,
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.site.settings.profile', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(
            'tenant.view,sites.view,sites.manage',
            $route->defaults['workspace_permissions'] ?? null,
        );
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Alpha Site', 'https://alpha.test/path');

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/settings")
            ->assertOk()
            ->assertSee('data-site-profile-form', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="url"', false)
            ->assertDontSee('name="tenant_id"', false)
            ->assertDontSee('name="site_id"', false)
            ->assertDontSee('name="actor_user_id"', false)
            ->assertDontSee('name="connection_status"', false);
    }

    public function test_save_profile_matches_source_normalization_and_verifies_authoritative_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'profile', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Before Name', 'https://before.test/', 'connected');

        $response = $this->actingAs($user)
            ->post("/tenants/profile/sites/{$site->id}/settings/profile", [
                'name' => '  Updated Site  ',
                'url' => 'HTTPS://Example.COM/path/to/wp?x=1#section',
            ]);

        $response
            ->assertRedirect("/tenants/profile/sites/{$site->id}/settings")
            ->assertSessionHas('status', 'Site profile updated.');

        $saved = Site::query()->withoutGlobalScopes()->findOrFail($site->id);
        $this->assertSame('Updated Site', $saved->name);
        $this->assertSame('https://example.com/', $saved->url);
        $this->assertSame('connected', $saved->connection_status);

        $this->actingAs($user)
            ->get("/tenants/profile/sites/{$site->id}/settings")
            ->assertOk()
            ->assertSee('Updated Site')
            ->assertSee('https://example.com/');
    }

    public function test_missing_permission_hides_control_and_direct_post_fails_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited-profile', ['tenant.view', 'sites.view']);
        $site = $this->site($membership->tenant, 'Limited Site', 'https://limited.test/');

        $this->actingAs($user)
            ->get("/tenants/limited-profile/sites/{$site->id}/settings")
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID);

        $this->actingAs($user)
            ->postJson("/tenants/limited-profile/sites/{$site->id}/settings/profile", [
                'name' => 'Tampered',
                'url' => 'https://tampered.test/',
            ])
            ->assertForbidden();

        $persisted = Site::query()->withoutGlobalScopes()->findOrFail($site->id);
        $this->assertSame('Limited Site', $persisted->name);
        $this->assertSame('https://limited.test/', $persisted->url);
    }

    public function test_foreign_tenant_and_foreign_site_ids_cannot_be_updated(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha-profile', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha->tenant, 'Alpha Site', 'https://alpha-profile.test/');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-profile', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($beta->tenant, 'Beta Site', 'https://beta-profile.test/');

        $this->actingAs($alphaUser)
            ->postJson("/tenants/beta-profile/sites/{$betaSite->id}/settings/profile", [
                'name' => 'Wrong Tenant',
                'url' => 'https://wrong-tenant.test/',
            ])
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->postJson("/tenants/alpha-profile/sites/{$betaSite->id}/settings/profile", [
                'name' => 'Wrong Site',
                'url' => 'https://wrong-site.test/',
            ])
            ->assertNotFound();

        $this->assertSame('Alpha Site', Site::query()->withoutGlobalScopes()->findOrFail($alphaSite->id)->name);
        $this->assertSame('Beta Site', Site::query()->withoutGlobalScopes()->findOrFail($betaSite->id)->name);
    }

    public function test_invalid_profile_and_server_derived_field_spoofing_are_rejected_before_write(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'validation-profile', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership->tenant, 'Valid Site', 'https://valid.test/', 'degraded');

        $this->actingAs($user)
            ->postJson("/tenants/validation-profile/sites/{$site->id}/settings/profile", [
                'name' => '   ',
                'url' => 'https://valid.test/',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($user)
            ->postJson("/tenants/validation-profile/sites/{$site->id}/settings/profile", [
                'name' => 'Valid Site',
                'url' => 'ftp://invalid.test/path',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->actingAs($user)
            ->postJson("/tenants/validation-profile/sites/{$site->id}/settings/profile", [
                'name' => 'Tampered',
                'url' => 'https://tampered.test/',
                'tenant_id' => 999,
                'site_id' => 999,
                'actor_user_id' => 999,
                'status' => 'disabled',
                'connection_status' => 'connected',
                'health_state' => 'healthy',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $persisted = Site::query()->withoutGlobalScopes()->findOrFail($site->id);
        $this->assertSame('Valid Site', $persisted->name);
        $this->assertSame('https://valid.test/', $persisted->url);
        $this->assertSame('degraded', $persisted->connection_status);
    }

    public function test_source_semantics_and_laravel_runtime_remain_local_tenant_scoped_and_provider_free(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $sourceService = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Services/SiteWebService.cs'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SiteSettingsSaveProfileController.php'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));

        $this->assertStringContainsString('OnSubmit="SaveProfileAsync"', $source);
        $this->assertStringContainsString('SiteService.UpdateSiteAsync(Id, _profile.Name, _profile.Url)', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);
        $this->assertStringContainsString('ValidateName(name)', $sourceService);
        $this->assertStringContainsString('NormalizeSiteUri(url)', $sourceService);

        $this->assertStringContainsString(self::OPERATION_ID, $controller);
        $this->assertStringContainsString("authorize('sites.manage')", $controller);
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString('Site profile could not be verified after persistence.', $controller);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringNotContainsString('paypal', strtolower($controller));
        $this->assertStringNotContainsString('payment', strtolower($controller));
        $this->assertStringNotContainsString('http::', strtolower($controller));
        $this->assertStringNotContainsString('dispatch(', $controller);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-profile-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(
        Tenant $tenant,
        string $name,
        string $url,
        string $connectionStatus = 'unknown',
    ): Site {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => $connectionStatus,
        ]);
        $context->forget();

        return $site;
    }
}
