<?php

namespace Tests\Feature;

use App\Email\Services\SiteMailProfileService;
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

final class SiteEmailSettingsClearMailPasswordTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-F56F7955DA';

    public function test_exact_clear_password_control_is_bound_to_guarded_delete_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor'));
        $view = (string) file_get_contents(resource_path('views/sites/email-settings.blade.php'));

        $this->assertStringContainsString('@onclick="ClearMailPasswordAsync"', $source);
        $this->assertStringContainsString('private async Task ClearMailPasswordAsync()', $source);
        $this->assertStringContainsString('MailService.ClearPasswordAsync', $source);

        $this->assertSame(self::OPERATION_ID, SiteMailProfileService::CLEAR_PASSWORD_OPERATION_ID);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('data-source-handler="ClearMailPasswordAsync"', $view);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/17/email-settings/mail-profile/password', 'DELETE'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@clearMailPassword',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.mail-profile-password-clear', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['DELETE'], $route->methods());
    }

    public function test_authorized_member_clears_only_saved_secret_and_page_rereads_password_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership, 'Alpha Mail');
        $this->withoutVite();

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$site->id}/email-settings/mail-profile", $this->profilePayload('super-secret'))
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-settings");

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertOk()
            ->assertSee('Clear saved password')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('super-secret');

        app(TenantContext::class)->activate($membership->tenant, $membership);
        $configuration = MailConfiguration::query()
            ->where('configuration_key', "site:{$site->id}")
            ->firstOrFail();
        $secretName = "email.transport.{$configuration->id}.credential";
        $this->assertDatabaseHas('tenant_secrets', [
            'tenant_id' => $membership->tenant_id,
            'name' => $secretName,
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->delete("/tenants/alpha/sites/{$site->id}/email-settings/mail-profile/password")
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertSessionHas('success', 'Saved SMTP password cleared.');

        app(TenantContext::class)->activate($membership->tenant, $membership);
        $configuration->refresh();
        $this->assertSame('smtp.alpha.test', $configuration->host);
        $this->assertSame(587, $configuration->port);
        $this->assertSame('mailer@alpha.test', $configuration->username);
        $this->assertSame('from@alpha.test', $configuration->from_address);
        $this->assertTrue((bool) $configuration->enabled);
        $this->assertFalse(TenantSecret::query()->where('name', $secretName)->exists());
        app(TenantContext::class)->forget();

        $response = $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertOk()
            ->assertSee('No password is currently stored.');

        $response->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);
    }

    public function test_missing_profile_permission_and_foreign_site_fail_closed(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha, 'Alpha');

        $this->actingAs($owner)
            ->delete("/tenants/alpha/sites/{$alphaSite->id}/email-settings/mail-profile/password")
            ->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited');

        $this->actingAs($limited)
            ->delete("/tenants/limited/sites/{$limitedSite->id}/email-settings/mail-profile/password")
            ->assertForbidden();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta, 'Beta');

        $this->actingAs($owner)
            ->delete("/tenants/alpha/sites/{$betaSite->id}/email-settings/mail-profile/password")
            ->assertNotFound();
    }

    private function profilePayload(string $password): array
    {
        return [
            'use_account_profile' => '0',
            'host' => 'smtp.alpha.test',
            'port' => '587',
            'username' => 'mailer@alpha.test',
            'password' => $password,
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha Notifications',
            'reply_to' => 'reply@alpha.test',
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
        $role = Role::query()->create(['name' => "clear-mail-password-{$slug}-{$user->id}"]);

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
