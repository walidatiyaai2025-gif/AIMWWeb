<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteEmailSettingsController;
use App\Models\MailConfiguration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSecret;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteEmailSettingsSaveMailProfileTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-609F15BFCA';

    public function test_exact_canonical_save_mail_profile_control_is_bound_to_a_guarded_post_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor'));
        $view = (string) file_get_contents(resource_path('views/sites/email-settings.blade.php'));

        $this->assertStringContainsString('@onclick="SaveMailProfileAsync"', $source);
        $this->assertStringContainsString('private async Task SaveMailProfileAsync()', $source);
        $this->assertStringContainsString('MailService.SaveAsync', $source);

        $this->assertSame(self::OPERATION_ID, SiteEmailSettingsController::SAVE_MAIL_PROFILE_OPERATION_ID);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('data-source-handler="SaveMailProfileAsync"', $view);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/17/email-settings/mail-profile', 'POST'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@saveMailProfile',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.mail-profile-save', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['POST'], $route->methods());
    }

    public function test_authorized_member_saves_real_profile_keeps_secret_out_of_html_and_preserves_it_when_blank(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership, 'Alpha Mail');
        $this->withoutVite();

        $payload = [
            'use_account_profile' => '0',
            'host' => 'smtp.alpha.test',
            'port' => '587',
            'username' => 'mailer@alpha.test',
            'password' => 'super-secret-password',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha Notifications',
            'reply_to' => 'reply@alpha.test',
            'enable_ssl' => '1',
            'enabled' => '1',
        ];

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-settings/mail-profile", $payload)
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertSessionHas('success', 'Mail profile saved securely.');

        app(TenantContext::class)->activate($membership->tenant, $membership);

        $configuration = MailConfiguration::query()
            ->where('configuration_key', "site:{$site->id}")
            ->firstOrFail();

        $this->assertSame($site->id, $configuration->site_id);
        $this->assertSame('smtp.alpha.test', $configuration->host);
        $this->assertSame(587, $configuration->port);
        $this->assertSame('mailer@alpha.test', $configuration->username);
        $this->assertSame('from@alpha.test', $configuration->from_address);
        $this->assertSame('reply@alpha.test', $configuration->reply_to);
        $this->assertSame('tls', $configuration->encryption);
        $this->assertTrue((bool) $configuration->enabled);

        $secret = TenantSecret::query()
            ->where('name', "email.transport.{$configuration->id}.credential")
            ->firstOrFail();

        $this->assertSame('super-secret-password', $secret->encrypted_value);
        $this->assertArrayNotHasKey('encrypted_value', $secret->toArray());

        $response = $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertOk()
            ->assertSee('smtp.alpha.test')
            ->assertSee('An encrypted password is saved. Leave this blank to keep it.')
            ->assertDontSee('super-secret-password');

        $response->assertSee('name="password" value=""', false);

        $payload['password'] = '';
        $payload['from_name'] = 'Alpha Updated';

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-settings/mail-profile", $payload)
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-settings");

        app(TenantContext::class)->activate($membership->tenant, $membership);

        $this->assertSame(
            'super-secret-password',
            TenantSecret::query()
                ->where('name', "email.transport.{$configuration->id}.credential")
                ->firstOrFail()
                ->encrypted_value,
        );
        $this->assertSame(
            'Alpha Updated',
            MailConfiguration::query()->findOrFail($configuration->id)->from_name,
        );

        app(TenantContext::class)->forget();
    }

    public function test_validation_permission_and_foreign_site_fail_closed_without_mutating_profile_state(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited');

        $this->actingAs($limited)
            ->post("/tenants/limited/sites/{$limitedSite->id}/email-settings/mail-profile", $this->validPayload())
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha, 'Alpha');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta, 'Beta');

        $this->actingAs($owner)
            ->post("/tenants/alpha/sites/{$betaSite->id}/email-settings/mail-profile", $this->validPayload())
            ->assertNotFound();

        $invalid = $this->validPayload();
        $invalid['host'] = '';
        $invalid['from_address'] = 'not-an-email';

        $this->actingAs($owner)
            ->from("/tenants/alpha/sites/{$alphaSite->id}/email-settings")
            ->post("/tenants/alpha/sites/{$alphaSite->id}/email-settings/mail-profile", $invalid)
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/email-settings")
            ->assertSessionHasErrors(['from_address']);

        $this->assertDatabaseMissing('mail_configurations', [
            'configuration_key' => "site:{$alphaSite->id}",
        ]);
    }

    private function validPayload(): array
    {
        return [
            'use_account_profile' => '0',
            'host' => 'smtp.example.test',
            'port' => '587',
            'username' => 'mailer@example.test',
            'password' => 'secret',
            'from_address' => 'from@example.test',
            'from_name' => 'Notifications',
            'reply_to' => 'reply@example.test',
            'enable_ssl' => '1',
            'enabled' => '1',
        ];
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
        $role = Role::query()->create(['name' => "save-mail-profile-{$slug}-{$user->id}"]);

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
