<?php

namespace Tests\Feature;

use App\Email\Services\MailConfigurationService;
use App\Http\Controllers\SiteEmailSettingsController;
use App\Models\MailConfiguration;
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

final class SiteEmailDiagnosticsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-E4EEF1914B';

    public function test_exact_canonical_operation_is_the_site_email_diagnostics_route(): void
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
        $this->assertSame('/sites/{SiteId:guid}/email-diagnostics', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteEmailDiagnostics.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_guarded_operation_bound_and_read_only(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/email-diagnostics', 'GET'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@diagnostics',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-diagnostics', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(
            SiteEmailSettingsRouteServiceProvider::DIAGNOSTICS_ROUTE_OPERATION_ID,
            $route->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('[0-9]+', $route->wheres['site'] ?? null);
    }

    public function test_authorized_member_renders_real_profile_and_recipient_without_secret_material(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership(
            $user,
            'alpha',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $site = $this->site($membership, 'Alpha SMTP Diagnostics');

        $this->activate($membership);
        $recipient = SiteEmailRecipient::query()->create([
            'site_id' => $site->id,
            'email_address' => 'alerts@alpha.test',
            'normalized_email_address' => 'ALERTS@ALPHA.TEST',
            'display_name' => 'Alerts',
            'is_enabled' => true,
            'created_by_user_id' => $membership->user_id,
        ]);
        app(MailConfigurationService::class)->save("site:{$site->id}", [
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'route-secret-must-not-render',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha',
            'enabled' => true,
            'settings' => ['use_account_profile' => false],
        ]);
        $beforeRecipient = $recipient->fresh()->toArray();
        $beforeProfile = MailConfiguration::query()
            ->where('configuration_key', "site:{$site->id}")
            ->firstOrFail()
            ->toArray();
        app(TenantContext::class)->forget();

        $this->withoutVite();
        $response = $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-diagnostics")
            ->assertOk()
            ->assertSee('Site email diagnostics')
            ->assertSee('Alpha SMTP Diagnostics')
            ->assertSee('smtp.alpha.test')
            ->assertSee('from@alpha.test')
            ->assertSee('alerts@alpha.test')
            ->assertSee('Back to email settings')
            ->assertDontSee('route-secret-must-not-render');

        foreach ([
            'password_ciphertext',
            'smtp_password',
            'client_secret',
            'provider_secret',
            'secret_ciphertext',
        ] as $secretMarker) {
            $response->assertDontSee($secretMarker);
        }

        $this->activate($membership);
        $this->assertSame($beforeRecipient, $recipient->fresh()->toArray());
        $this->assertSame(
            $beforeProfile,
            MailConfiguration::query()
                ->where('configuration_key', "site:{$site->id}")
                ->firstOrFail()
                ->toArray(),
            'Opening the diagnostics route must not mutate persisted SMTP state.',
        );
        app(TenantContext::class)->forget();
    }

    public function test_empty_state_guest_permission_and_cross_tenant_paths_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/sites/1/email-diagnostics')->assertRedirect('/login');

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limited, 'Limited Diagnostics');
        $this->actingAs($limitedUser)
            ->get("/tenants/limited/sites/{$limitedSite->id}/email-diagnostics")
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Empty Diagnostics');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta, 'Beta Diagnostics');

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics")
            ->assertOk()
            ->assertSee('No saved SMTP profile exists for this site yet.')
            ->assertSee('No enabled recipient is configured.');

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$betaSite->id}/email-diagnostics")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/beta/sites/{$betaSite->id}/email-diagnostics")
            ->assertNotFound();
    }

    public function test_sibling_diagnostics_controls_remain_separate_canonical_operations(): void
    {
        $diagnose = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/email-diagnostics/diagnose', 'POST'),
        );
        $sendTest = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/email-diagnostics/send-test', 'POST'),
        );

        $this->assertSame(
            SiteEmailSettingsController::DIAGNOSE_OPERATION_ID,
            $diagnose->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertSame(
            SiteEmailSettingsController::SEND_TEST_OPERATION_ID,
            $sendTest->defaults['canonical_operation_id'] ?? null,
        );
        $this->assertNotSame(self::OPERATION_ID, $diagnose->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, $sendTest->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, SiteEmailSettingsController::DIAGNOSTICS_BACK_OPERATION_ID);
    }

    public function test_source_and_laravel_authorization_contracts_match_the_same_route(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailDiagnostics.razor'),
        );
        $provider = (string) file_get_contents(
            app_path('Providers/SiteEmailSettingsRouteServiceProvider.php'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SiteEmailSettingsController.php'),
        );
        $view = (string) file_get_contents(
            resource_path('views/sites/email-diagnostics.blade.php'),
        );

        $this->assertStringContainsString('@page "/sites/{SiteId:guid}/email-diagnostics"', $source);
        $this->assertStringContainsString('RecipientService.GetAsync(SiteId)', $source);
        $this->assertStringContainsString('GetSiteTestProfileAsync', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $provider);
        $this->assertStringContainsString(
            "get('/tenants/{tenant}/sites/{site}/email-diagnostics'",
            $provider,
        );
        $this->assertStringContainsString("['web', 'auth', 'tenant.context']", $provider);
        $this->assertStringContainsString("authorize('tenant.view')", $controller);
        $this->assertStringContainsString("authorize('sites.view')", $controller);
        $this->assertStringContainsString("authorize('settings.manage')", $controller);
        $this->assertStringContainsString('Site::query()->findOrFail($site)', $controller);
        $this->assertStringContainsString('sites.email-diagnostics', $controller);
        $this->assertStringContainsString('No saved SMTP profile exists for this site yet.', $view);
        $this->assertStringNotContainsString('password_ciphertext', strtolower($view));
        $this->assertStringNotContainsString('smtp_password', strtolower($view));
    }

    private function membership(
        User $user,
        string $slug,
        array $permissions,
    ): TenantMembership {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "email-diagnostics-route-{$slug}-{$user->id}",
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh(['tenant', 'user']);
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $this->activate($membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function activate(TenantMembership $membership): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
    }
}
