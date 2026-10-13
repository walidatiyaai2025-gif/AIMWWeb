<?php

namespace Tests\Feature;

use App\Email\Services\EmailSecretStore;
use App\Email\Services\MailConfigurationService;
use App\Models\MailConfiguration;
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

final class AccountEmailSettingsClearPasswordTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-54E7EEFB15';

    public function test_route_is_tenant_scoped_permission_guarded_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/email/configuration/secret', 'DELETE'));

        $this->assertSame('api.v1.email.configuration.secret.clear', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['DELETE'], $route->methods());
    }

    public function test_clear_password_removes_only_secret_and_preserves_mail_profile(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.manage']);

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $configuration = app(MailConfigurationService::class)->save('default', [
            'transport' => 'smtp',
            'host' => 'smtp.alpha.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'super-secret',
            'from_address' => 'from@alpha.test',
            'from_name' => 'Alpha Mail',
            'reply_to' => 'reply@alpha.test',
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ]);
        $this->assertTrue(app(EmailSecretStore::class)->has($configuration));
        $configurationId = $configuration->id;
        $context->forget();

        $this->actingAs($user)
            ->deleteJson('/api/v1/tenants/alpha/email/configuration/secret', ['configuration_key' => 'default'])
            ->assertOk()
            ->assertJsonPath('configuration_key', 'default')
            ->assertJsonPath('has_secret', false)
            ->assertJsonPath('host', 'smtp.alpha.test')
            ->assertJsonPath('port', 587)
            ->assertJsonPath('from_address', 'from@alpha.test')
            ->assertJsonPath('enabled', true);

        $context->activate($membership->tenant, $membership);
        $configuration = MailConfiguration::query()->findOrFail($configurationId);
        $this->assertSame('smtp.alpha.test', $configuration->host);
        $this->assertSame(587, $configuration->port);
        $this->assertSame('mailer@alpha.test', $configuration->username);
        $this->assertSame('from@alpha.test', $configuration->from_address);
        $this->assertSame('Alpha Mail', $configuration->from_name);
        $this->assertSame('reply@alpha.test', $configuration->reply_to);
        $this->assertTrue((bool) $configuration->enabled);
        $this->assertFalse(app(EmailSecretStore::class)->has($configuration));
        $context->forget();
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->deleteJson('/api/v1/tenants/alpha/email/configuration/secret', ['configuration_key' => 'default'])
            ->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->deleteJson('/api/v1/tenants/limited/email/configuration/secret', ['configuration_key' => 'default'])
            ->assertForbidden();

        $owner = User::factory()->create();
        $this->membership($owner, 'alpha', ['tenant.manage']);
        $this->actingAs($owner)
            ->deleteJson('/api/v1/tenants/beta/email/configuration/secret', ['configuration_key' => 'default'])
            ->assertNotFound();
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
        $role = Role::query()->create(['name' => "account-email-clear-password-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
