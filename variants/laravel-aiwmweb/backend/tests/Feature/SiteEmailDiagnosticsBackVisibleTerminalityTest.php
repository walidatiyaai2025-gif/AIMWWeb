<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteEmailSettingsController;
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

final class SiteEmailDiagnosticsBackVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-8E56589573';

    public function test_exact_canonical_back_control_is_adapted(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('email', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/email-diagnostics', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteEmailDiagnostics.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_diagnostics_route_is_explicit_guarded_and_separate_from_the_visible_control_identity(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/123/email-diagnostics', 'GET'));

        $this->assertSame(
            SiteEmailSettingsController::class.'@diagnostics',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-diagnostics', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('[0-9]+', $route->wheres['site'] ?? null);
    }

    public function test_authorized_member_renders_real_site_state_and_back_link_without_secret_material(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership, 'Alpha Mail Diagnostics');
        $this->withoutVite();

        $response = $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-diagnostics")
            ->assertOk()
            ->assertSee('Site email diagnostics')
            ->assertSee('Alpha Mail Diagnostics')
            ->assertSee('No saved SMTP profile exists for this site yet.')
            ->assertSee('Back to email settings')
            ->assertSee(
                "/tenants/alpha/sites/{$site->id}/email-settings",
                false,
            )
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);

        foreach ([
            'password_ciphertext',
            'smtp_password',
            'client_secret',
            'provider_secret',
            'secret_ciphertext',
        ] as $secretMarker) {
            $response->assertDontSee($secretMarker);
        }
    }

    public function test_guest_permission_and_cross_tenant_site_paths_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/sites/1/email-diagnostics')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Diagnostics');
        $this->actingAs($limited)
            ->get("/tenants/limited/sites/{$limitedSite->id}/email-diagnostics")
            ->assertForbidden();

        $alpha = User::factory()->create();
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Diagnostics');

        $beta = User::factory()->create();
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($betaMembership, 'Beta Diagnostics');

        $this->actingAs($alpha)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics")
            ->assertOk();
        $this->actingAs($alpha)
            ->get("/tenants/alpha/sites/{$betaSite->id}/email-diagnostics")
            ->assertNotFound();
        $this->actingAs($alpha)
            ->get("/tenants/beta/sites/{$betaSite->id}/email-diagnostics")
            ->assertNotFound();
    }

    public function test_source_and_laravel_bind_the_same_site_specific_back_navigation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailDiagnostics.razor'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/SiteEmailSettingsController.php'));
        $view = (string) file_get_contents(resource_path('views/sites/email-diagnostics.blade.php'));

        $this->assertStringContainsString('@page "/sites/{SiteId:guid}/email-diagnostics"', $source);
        $this->assertStringContainsString('Back to email settings', $source);
        $this->assertStringContainsString('/sites/{SiteId}/email-settings', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $controller);
        $this->assertStringContainsString("authorize('tenant.view')", $controller);
        $this->assertStringContainsString("authorize('sites.view')", $controller);
        $this->assertStringContainsString("authorize('settings.manage')", $controller);
        $this->assertStringContainsString("route('canonical.workspace.site-email-settings.site'", $view);
        $this->assertStringContainsString('data-canonical-operation="{{ $backOperationId }}"', $view);
        $this->assertStringNotContainsString('password_ciphertext', strtolower($view));
        $this->assertStringNotContainsString('smtp_password', strtolower($view));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "email-diagnostics-back-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
