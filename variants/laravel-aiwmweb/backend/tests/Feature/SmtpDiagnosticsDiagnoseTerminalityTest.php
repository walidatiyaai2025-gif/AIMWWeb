<?php

namespace Tests\Feature;

use App\Email\Contracts\EmailTransport;
use App\Email\Contracts\SmtpConnectionProbe;
use App\Email\Services\MailConfigurationService;
use App\Http\Controllers\SiteEmailSettingsController;
use App\Models\MailConfiguration;
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

final class SmtpDiagnosticsDiagnoseTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-EE42461195';

    public function test_source_and_route_bind_exact_diagnose_async_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/SmtpDiagnosticsPanel.razor'),
        );

        foreach ([
            '@onclick="DiagnoseAsync"',
            'private async Task DiagnoseAsync()',
            '_diagnostics.DiagnoseAsync(profile)',
            'if (_busy) return;',
        ] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/17/email-diagnostics/diagnose', 'POST'),
        );

        $this->assertSame(SiteEmailSettingsController::class.'@diagnoseConnection', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.site-email-diagnostics.diagnose', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_diagnosis_checks_config_dns_and_tcp_without_sending_mail(): void
    {
        $transport = new DiagnoseOnlyTransport;
        $probe = new DeterministicSmtpProbe(true, true);
        $this->app->instance(EmailTransport::class, $transport);
        $this->app->instance(SmtpConnectionProbe::class, $probe);

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership);

        $this->activate($membership);
        app(MailConfigurationService::class)->save("site:{$site->id}", [
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'saved-secret',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha',
            'enabled' => true,
            'settings' => ['use_account_profile' => false],
        ]);
        app(TenantContext::class)->forget();

        $response = $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-diagnostics/diagnose")
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-diagnostics");

        $result = $response->getSession()->get('smtp_diagnostic_result');
        $this->assertTrue((bool) ($result['is_success'] ?? false));
        $this->assertSame(['CONFIG', 'DNS', 'TCP'], array_column($result['steps'] ?? [], 'name'));
        $this->assertSame('smtp.alpha.test', $probe->host);
        $this->assertSame(587, $probe->port);
        $this->assertSame(1, $transport->diagnoseAttempts);
        $this->assertSame(0, $transport->sendAttempts);

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-diagnostics")
            ->assertOk()
            ->assertSee('Diagnose connection')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('data-source-handler="DiagnoseAsync"', false);
    }

    public function test_dns_or_tcp_failure_is_truthful_and_permissions_and_tenant_isolation_fail_closed(): void
    {
        $transport = new DiagnoseOnlyTransport;
        $probe = new DeterministicSmtpProbe(true, false);
        $this->app->instance(EmailTransport::class, $transport);
        $this->app->instance(SmtpConnectionProbe::class, $probe);

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha);
        $this->saveProfile($alpha, $alphaSite);

        $response = $this->actingAs($owner)
            ->post("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics/diagnose");

        $result = $response->getSession()->get('smtp_diagnostic_result');
        $this->assertFalse((bool) ($result['is_success'] ?? true));
        $this->assertSame('TCP', $result['steps'][2]['name'] ?? null);
        $this->assertFalse((bool) ($result['steps'][2]['is_success'] ?? true));
        $this->assertSame(0, $transport->sendAttempts);

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limited);
        $this->actingAs($limitedUser)
            ->post("/tenants/limited/sites/{$limitedSite->id}/email-diagnostics/diagnose")
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta);
        $this->actingAs($owner)
            ->post("/tenants/alpha/sites/{$betaSite->id}/email-diagnostics/diagnose")
            ->assertNotFound();
    }

    private function saveProfile(TenantMembership $membership, Site $site): void
    {
        $this->activate($membership);
        app(MailConfigurationService::class)->save("site:{$site->id}", [
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'secret' => '',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha',
            'enabled' => true,
            'settings' => ['use_account_profile' => false],
        ]);
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "smtp-diagnose-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh(['tenant', 'user']);
    }

    private function site(TenantMembership $membership): Site
    {
        $this->activate($membership);
        $site = Site::query()->create([
            'name' => 'SMTP Site',
            'url' => 'https://smtp-site.example.test',
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

final class DiagnoseOnlyTransport implements EmailTransport
{
    public int $diagnoseAttempts = 0;

    public int $sendAttempts = 0;

    public function send(MailConfiguration $configuration, ?string $secret, array $message): array
    {
        $this->sendAttempts++;

        return [];
    }

    public function diagnose(MailConfiguration $configuration, ?string $secret): array
    {
        $this->diagnoseAttempts++;

        return ['ok' => true, 'message' => 'SMTP configuration is syntactically valid.'];
    }
}

final class DeterministicSmtpProbe implements SmtpConnectionProbe
{
    public ?string $host = null;

    public ?int $port = null;

    public function __construct(private readonly bool $dnsOk, private readonly bool $tcpOk) {}

    public function diagnose(string $host, int $port): array
    {
        $this->host = $host;
        $this->port = $port;

        return [
            'dns_ok' => $this->dnsOk,
            'dns_message' => $this->dnsOk ? 'DNS resolved.' : 'DNS failed.',
            'tcp_ok' => $this->tcpOk,
            'tcp_message' => $this->tcpOk ? 'TCP connected.' : 'TCP failed.',
        ];
    }
}
