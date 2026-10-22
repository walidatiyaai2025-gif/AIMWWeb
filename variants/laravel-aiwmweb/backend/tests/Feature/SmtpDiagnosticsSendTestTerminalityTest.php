<?php

namespace Tests\Feature;

use App\Email\Contracts\EmailTransport;
use App\Email\Exceptions\EmailTransportException;
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

final class SmtpDiagnosticsSendTestTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-BC093D6418';

    public function test_source_semantics_and_guarded_post_route_match_send_test_async(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/SmtpDiagnosticsPanel.razor'),
        );

        $this->assertStringContainsString('@onclick="SendTestAsync"', $source);
        $this->assertStringContainsString('private async Task SendTestAsync()', $source);
        $this->assertStringContainsString('_diagnostics.SendTestAsync(profile, _testRecipient)', $source);
        $this->assertStringContainsString(
            'if (_busy || string.IsNullOrWhiteSpace(_testRecipient)) return;',
            $source,
        );

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/17/email-diagnostics/send-test', 'POST'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@sendTest',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-diagnostics.send-test', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['POST'], $route->methods());
    }

    public function test_authorized_member_sends_real_test_message_and_renders_verified_result(): void
    {
        $transport = new SmtpSendTestTransport;
        $this->app->instance(EmailTransport::class, $transport);

        $user = User::factory()->create();
        $membership = $this->membership(
            $user,
            'alpha',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $site = $this->site($membership, 'Alpha SMTP');

        $this->activate($membership);
        app(MailConfigurationService::class)->save("site:{$site->id}", [
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'top-secret-password',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha',
            'enabled' => true,
            'settings' => ['use_account_profile' => false],
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-diagnostics/send-test", [
                'recipient' => 'recipient@example.test',
            ])
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-diagnostics")
            ->assertSessionHas('smtp_diagnostic_result', function (array $result): bool {
                return ($result['is_success'] ?? false) === true
                    && str_contains((string) ($result['message'] ?? ''), 'Test email sent successfully');
            });

        $this->assertSame(1, $transport->sendAttempts);
        $this->assertSame('recipient@example.test', $transport->lastMessage['to'] ?? null);
        $this->assertSame('AI WordPress Manager - SMTP test', $transport->lastMessage['subject'] ?? null);

        $this->actingAs($user)
            ->withSession([
                'smtp_diagnostic_result' => [
                    'is_success' => true,
                    'message' => 'Test email sent successfully.',
                    'duration_milliseconds' => 7,
                    'steps' => [
                        ['name' => 'CONFIG', 'is_success' => true, 'message' => 'Configuration passed.'],
                        ['name' => 'SMTP', 'is_success' => true, 'message' => 'Accepted.'],
                    ],
                ],
            ])
            ->get("/tenants/alpha/sites/{$site->id}/email-diagnostics")
            ->assertOk()
            ->assertSee('Send test email')
            ->assertSee('PASS')
            ->assertSee('Test email sent successfully.')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('data-source-handler="SendTestAsync"', false);
    }

    public function test_invalid_recipient_permission_and_cross_tenant_access_fail_closed(): void
    {
        $transport = new SmtpSendTestTransport;
        $this->app->instance(EmailTransport::class, $transport);

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limited, 'Limited SMTP');

        $this->actingAs($limitedUser)
            ->post("/tenants/limited/sites/{$limitedSite->id}/email-diagnostics/send-test", [
                'recipient' => 'recipient@example.test',
            ])
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha, 'Alpha SMTP');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta, 'Beta SMTP');

        $this->actingAs($owner)
            ->from("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics")
            ->post("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics/send-test", [
                'recipient' => 'not-an-email',
            ])
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/email-diagnostics")
            ->assertSessionHasErrors(['recipient']);

        $this->actingAs($owner)
            ->post("/tenants/alpha/sites/{$betaSite->id}/email-diagnostics/send-test", [
                'recipient' => 'recipient@example.test',
            ])
            ->assertNotFound();

        $this->assertSame(0, $transport->sendAttempts);
    }

    public function test_transport_failure_is_visible_but_secret_material_is_redacted(): void
    {
        $transport = new SmtpSendTestTransport(
            new EmailTransportException(
                'AUTHENTICATION_CONFIG_FAILURE',
                false,
                'authentication failed password=do-not-leak token=private-token',
            ),
        );
        $this->app->instance(EmailTransport::class, $transport);

        $user = User::factory()->create();
        $membership = $this->membership(
            $user,
            'alpha',
            ['tenant.view', 'sites.view', 'settings.manage'],
        );
        $site = $this->site($membership, 'Alpha SMTP');

        $this->activate($membership);
        app(MailConfigurationService::class)->save("site:{$site->id}", [
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'do-not-leak',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha',
            'enabled' => true,
            'settings' => ['use_account_profile' => false],
        ]);
        app(TenantContext::class)->forget();

        $response = $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-diagnostics/send-test", [
                'recipient' => 'recipient@example.test',
            ])
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-diagnostics");

        $result = $response->getSession()->get('smtp_diagnostic_result');
        $json = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertFalse((bool) ($result['is_success'] ?? true));
        $this->assertSame('TLS/AUTH', $result['steps'][1]['name'] ?? null);
        $this->assertStringNotContainsString('do-not-leak', $json);
        $this->assertStringNotContainsString('private-token', $json);
        $this->assertStringContainsString('[REDACTED]', $json);
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
        $role = Role::query()->create(['name' => "smtp-send-test-{$slug}-{$user->id}"]);

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

final class SmtpSendTestTransport implements EmailTransport
{
    public int $sendAttempts = 0;

    public array $lastMessage = [];

    public function __construct(private readonly ?EmailTransportException $failure = null) {}

    public function send(MailConfiguration $configuration, ?string $secret, array $message): array
    {
        $this->sendAttempts++;
        $this->lastMessage = $message;

        if ($this->failure) {
            throw $this->failure;
        }

        return ['provider_message_id' => 'smtp-test-message'];
    }

    public function diagnose(MailConfiguration $configuration, ?string $secret): array
    {
        return ['ok' => true, 'message' => 'SMTP configuration is syntactically valid.'];
    }
}
