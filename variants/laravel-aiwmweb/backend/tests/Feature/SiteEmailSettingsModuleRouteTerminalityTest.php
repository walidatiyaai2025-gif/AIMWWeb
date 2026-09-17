<?php

namespace Tests\Feature;

use App\Email\Services\SiteEmailRecipientService;
use App\Http\Controllers\SiteEmailSettingsController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteEmailRecipient;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\SiteEmailSettingsRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteEmailSettingsModuleRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-7F2D7C5921';

    public function test_exact_canonical_operation_is_the_module_site_email_settings_route(): void
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
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/module/site-email-settings', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_module_route_is_explicit_guarded_operation_bound_and_read_only(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/module/site-email-settings', 'GET'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@module',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.module', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(
            SiteEmailSettingsRouteServiceProvider::MODULE_ROUTE_OPERATION_ID,
            $route->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_authorized_member_renders_only_authoritative_active_tenant_data_without_secret_material(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership(
            $alphaUser,
            'alpha',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $alphaSite = $this->site($alphaMembership, 'Alpha Mail Site');
        $alphaRecipient = $this->recipient($alphaMembership, $alphaSite, 'alerts@example.test');
        $before = $alphaRecipient->fresh()->toArray();

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership(
            $betaUser,
            'beta',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $betaSite = $this->site($betaMembership, 'Beta Secret Site');
        $this->recipient($betaMembership, $betaSite, 'beta-secret@example.test');

        $this->withoutVite();
        $response = $this->actingAs($alphaUser)
            ->get("/tenants/alpha/module/site-email-settings?site={$alphaSite->id}")
            ->assertOk()
            ->assertSee('Site email settings')
            ->assertSee('Alpha Mail Site')
            ->assertSee('alerts@example.test')
            ->assertDontSee('Beta Secret Site')
            ->assertDontSee('beta-secret@example.test');

        foreach ([
            'PasswordCiphertext',
            'password_ciphertext',
            'SmtpPassword',
            'smtp_password',
            'client_secret',
            'provider_secret',
        ] as $secretMarker) {
            $response->assertDontSee($secretMarker);
        }

        $this->assertSame(
            $before,
            $alphaRecipient->fresh()->toArray(),
            'Opening the module GET route must not mutate persisted recipient state.',
        );
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_query_site_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/module/site-email-settings')->assertRedirect('/login');

        $limitedUser = User::factory()->create();
        $limitedMembership = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Mail Site');
        $this->actingAs($limitedUser)
            ->get("/tenants/limited/module/site-email-settings?site={$limitedSite->id}")
            ->assertForbidden();

        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership(
            $alphaUser,
            'alpha',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $alphaSite = $this->site($alphaMembership, 'Alpha Mail Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership(
            $betaUser,
            'beta',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $betaSite = $this->site($betaMembership, 'Beta Mail Site');

        $this->actingAs($alphaUser)
            ->get("/tenants/alpha/module/site-email-settings?site={$alphaSite->id}")
            ->assertOk();
        $this->actingAs($alphaUser)
            ->get("/tenants/alpha/module/site-email-settings?site={$betaSite->id}")
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get("/tenants/beta/module/site-email-settings?site={$betaSite->id}")
            ->assertNotFound();
    }

    public function test_site_specific_route_and_recipient_mutation_remain_separate_canonical_operations(): void
    {
        $siteRoute = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/email-settings', 'GET'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.site', $siteRoute->getName());
        $this->assertSame(
            SiteEmailSettingsRouteServiceProvider::SITE_ROUTE_OPERATION_ID,
            $siteRoute->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertNotSame(self::OPERATION_ID, $siteRoute->defaults['canonical_operation_id'] ?? null);

        $mutation = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/email-settings/recipients', 'POST'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.recipient-add', $mutation->getName());
        $this->assertSame(
            SiteEmailRecipientService::ADD_OPERATION_ID,
            $mutation->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertNotSame(self::OPERATION_ID, $mutation->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $mutation->gatherMiddleware());
        $this->assertContains('auth', $mutation->gatherMiddleware());
        $this->assertContains('tenant.context', $mutation->gatherMiddleware());
        $this->assertSame(['POST'], $mutation->methods());
    }

    public function test_source_and_laravel_authorization_contracts_remain_tenant_and_secret_safe(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SiteEmailSettingsController.php'),
        );
        $provider = (string) file_get_contents(
            app_path('Providers/SiteEmailSettingsRouteServiceProvider.php'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/email-settings.blade.php'));

        $this->assertStringContainsString('@page "/module/site-email-settings"', $source);
        $this->assertStringContainsString(
            'Passwords are stored encrypted and are never displayed after saving.',
            $source,
        );
        $this->assertStringContainsString("authorize('tenant.view')", $controller);
        $this->assertStringContainsString("authorize('sites.view')", $controller);
        $this->assertStringContainsString("authorize('settings.manage')", $controller);
        $this->assertStringContainsString('TenantContext', $controller);
        $this->assertStringContainsString('findOrFail($requestedSite)', $controller);
        $this->assertStringContainsString(self::OPERATION_ID, $provider);
        $this->assertStringContainsString("['web', 'auth', 'tenant.context']", $provider);
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringNotContainsString('password_ciphertext', strtolower($view));
        $this->assertStringNotContainsString('smtp_password', strtolower($view));
        $this->assertStringNotContainsString('client_secret', strtolower($view));
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
        $role = Role::query()->create(['name' => "site-email-module-{$slug}-{$user->id}"]);
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

    private function recipient(TenantMembership $membership, Site $site, string $email): SiteEmailRecipient
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $recipient = SiteEmailRecipient::query()->create([
            'site_id' => $site->id,
            'email_address' => $email,
            'normalized_email_address' => mb_strtoupper($email, 'UTF-8'),
            'display_name' => 'Alerts',
            'is_enabled' => true,
            'created_by_user_id' => $membership->user_id,
        ]);
        $context->forget();

        return $recipient;
    }
}
