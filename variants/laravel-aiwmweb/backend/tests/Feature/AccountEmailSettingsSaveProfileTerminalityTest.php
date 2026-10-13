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

final class AccountEmailSettingsSaveProfileTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-00CC1272F6';

    public function test_route_is_tenant_scoped_permission_guarded_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/email/configuration', 'PUT'));

        $this->assertSame('api.v1.email.configuration.save', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['PUT'], $route->methods());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_save_updates_profile_and_blank_secret_preserves_existing_credential(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.manage']);

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $configuration = app(MailConfigurationService::class)->save('default', [
            'transport' => 'smtp',
            'host' => 'smtp.old.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@alpha.test',
            'secret' => 'existing-secret',
            'from_address' => 'old@alpha.test',
            'from_name' => 'Alpha Mail',
            'reply_to' => 'reply@alpha.test',
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ]);
        $configurationId = $configuration->id;
        $this->assertTrue(app(EmailSecretStore::class)->has($configuration));
        $context->forget();

        $response = $this->actingAs($user)
            ->putJson('/api/v1/tenants/alpha/email/configuration', [
                'configuration_key' => 'default',
                'transport' => 'smtp',
                'host' => 'smtp.new.test',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'mailer2@alpha.test',
                'secret' => null,
                'from_address' => 'new@alpha.test',
                'from_name' => 'Alpha New Mail',
                'reply_to' => 'reply2@alpha.test',
                'enabled' => false,
                'timeout_seconds' => 30,
                'max_attempts' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('configuration_key', 'default')
            ->assertJsonPath('host', 'smtp.new.test')
            ->assertJsonPath('port', 465)
            ->assertJsonPath('has_secret', true);

        $this->assertStringNotContainsString('existing-secret', $response->getContent());

        $context->activate($membership->tenant, $membership);
        $configuration = MailConfiguration::query()->findOrFail($configurationId);
        $this->assertSame('smtp.new.test', $configuration->host);
        $this->assertSame(465, $configuration->port);
        $this->assertSame('mailer2@alpha.test', $configuration->username);
        $this->assertSame('new@alpha.test', $configuration->from_address);
        $this->assertSame('Alpha New Mail', $configuration->from_name);
        $this->assertSame('reply2@alpha.test', $configuration->reply_to);
        $this->assertFalse((bool) $configuration->enabled);
        $this->assertTrue(app(EmailSecretStore::class)->has($configuration));
        $context->forget();
    }

    public function test_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $payload = [
            'configuration_key' => 'default',
            'transport' => 'smtp',
            'host' => 'smtp.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => null,
            'secret' => null,
            'from_address' => 'from@example.test',
            'from_name' => 'Mail',
            'reply_to' => null,
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ];

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->putJson('/api/v1/tenants/limited/email/configuration', $payload)
            ->assertForbidden();

        $owner = User::factory()->create();
        $this->membership($owner, 'alpha', ['tenant.manage']);
        $this->actingAs($owner)
            ->putJson('/api/v1/tenants/beta/email/configuration', $payload)
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
        $role = Role::query()->create(['name' => "account-email-save-profile-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
