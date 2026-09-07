<?php

namespace Tests\Feature;

use App\AI\Platform\Enums\ProviderReadiness;
use App\AI\Platform\Services\ProviderSecretStore;
use App\Http\Controllers\AiProviderApiKeyRemovalController;
use App\Models\AiProviderProfile;
use App\Models\AuditEvent;
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

class AiProviderConfirmApiKeyRemovalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-6701FB22AE';

    public function test_exact_canonical_operation_is_confirm_api_key_removal(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/settings/ai-providers', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? [ConfirmRemovalAndSaveAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor', $operation['current_source']);
        $this->assertTrue((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertContains($operation['migration_state'], ['PENDING', 'ADAPTED']);
    }

    public function test_delete_route_is_explicit_guarded_and_carries_operation_provenance(): void
    {
        $route = Route::getRoutes()->match(Request::create(
            '/tenants/alpha/settings/ai-providers/openai/api-key',
            'DELETE',
        ));

        $this->assertSame(
            AiProviderApiKeyRemovalController::class,
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('tenant.settings.ai-providers.api-key.destroy', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'provider'], $route->parameterNames());
    }

    public function test_configured_key_renders_source_confirmation_contract_without_secret_material(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');
        [$provider, $secret] = $this->providerWithSecret($membership, 'openai');

        $this->actingAs($user)
            ->get('/tenants/alpha/settings/ai-providers')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Confirm API key removal')
            ->assertSee('You are about to remove stored encrypted keys from provider settings.')
            ->assertSee('A provider that requires a key may stop executing AI requests after this save.')
            ->assertSee('You can add a new key later, but the deleted value itself cannot be recovered from the UI.')
            ->assertSee('Type REMOVE to confirm')
            ->assertSee('Remove keys and save')
            ->assertSee(route('tenant.settings.ai-providers.api-key.destroy', [
                'tenant' => 'alpha',
                'provider' => $provider->provider_key,
            ]), false)
            ->assertDontSee($secret);
    }

    public function test_confirmation_must_be_exact_remove_and_failed_confirmation_does_not_mutate(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');
        [$provider] = $this->providerWithSecret($membership, 'openai');

        $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-providers')
            ->delete('/tenants/alpha/settings/ai-providers/openai/api-key', ['confirmation' => 'remove'])
            ->assertRedirect('/tenants/alpha/settings/ai-providers')
            ->assertSessionHasErrors('confirmation');

        $this->activate($membership);
        $this->assertTrue(app(ProviderSecretStore::class)->has($provider));
        $this->assertNotSame(ProviderReadiness::NotConfigured, $provider->fresh()->readiness_state);
        app(TenantContext::class)->forget();
    }

    public function test_exact_remove_clears_only_the_encrypted_key_resets_readiness_audits_and_rereads(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');
        [$provider, $secret] = $this->providerWithSecret($membership, 'openai');

        $this->actingAs($user)
            ->delete('/tenants/alpha/settings/ai-providers/openai/api-key', ['confirmation' => 'REMOVE'])
            ->assertRedirect('/tenants/alpha/settings/ai-providers')
            ->assertSessionHas('status');

        $this->activate($membership);
        $this->assertFalse(app(ProviderSecretStore::class)->has($provider));
        $provider->refresh();
        $this->assertSame(ProviderReadiness::NotConfigured, $provider->readiness_state);
        $this->assertSame('API credential is not configured.', $provider->readiness_error);
        $this->assertNotNull($provider->readiness_checked_at);

        $audit = AuditEvent::query()
            ->where('event', 'ai.provider.credential_cleared')
            ->where('subject_type', 'AiProviderProfile')
            ->where('subject_id', $provider->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(['provider_key' => 'openai'], $audit->metadata);
        $this->assertStringNotContainsString($secret, (string) json_encode($audit->metadata));
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->get('/tenants/alpha/settings/ai-providers')
            ->assertOk()
            ->assertSee('Not configured')
            ->assertDontSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee($secret);
    }

    public function test_missing_settings_manage_is_forbidden_and_preserves_the_secret(): void
    {
        $limited = User::factory()->create();
        $membership = $this->membership($limited, 'limited', ['tenant.view'], 'Limited');
        [$provider] = $this->providerWithSecret($membership, 'openai');

        $this->actingAs($limited)
            ->delete('/tenants/limited/settings/ai-providers/openai/api-key', ['confirmation' => 'REMOVE'])
            ->assertForbidden();

        $this->activate($membership);
        $this->assertTrue(app(ProviderSecretStore::class)->has($provider));
        app(TenantContext::class)->forget();
    }

    public function test_cross_tenant_delete_is_404_and_cannot_clear_another_tenant_secret(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['settings.manage'], 'Alpha Settings');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['settings.manage'], 'Beta Settings');
        [$betaProvider] = $this->providerWithSecret($betaMembership, 'openai');

        $this->actingAs($alphaUser)
            ->delete('/tenants/beta/settings/ai-providers/openai/api-key', ['confirmation' => 'REMOVE'])
            ->assertNotFound();

        $this->activate($betaMembership);
        $this->assertTrue(app(ProviderSecretStore::class)->has($betaProvider));
        app(TenantContext::class)->forget();

        $this->activate($alphaMembership);
        $this->assertFalse(AiProviderProfile::query()->where('provider_key', 'openai')->exists());
        app(TenantContext::class)->forget();
    }

    private function providerWithSecret(TenantMembership $membership, string $providerKey): array
    {
        $this->activate($membership);
        $provider = AiProviderProfile::query()->create([
            'provider_key' => $providerKey,
            'adapter_key' => 'openai',
            'display_name' => 'OpenAI Production',
            'endpoint' => 'https://provider.example.test/v1',
            'default_model' => 'gpt-test',
            'enabled' => true,
            'priority' => 5,
            'readiness_state' => ProviderReadiness::Ready,
            'readiness_checked_at' => now(),
        ]);
        $secret = 'sk-live-super-secret-'.$membership->tenant_id;
        app(ProviderSecretStore::class)->put($provider, $secret);
        app(TenantContext::class)->forget();

        return [$provider, $secret];
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function activate(TenantMembership $membership): void
    {
        app(TenantContext::class)->activate($membership->tenant);
    }
}
