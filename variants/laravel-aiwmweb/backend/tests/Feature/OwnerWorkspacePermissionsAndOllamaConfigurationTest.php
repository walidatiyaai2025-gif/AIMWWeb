<?php

namespace Tests\Feature;

use App\Auth\OwnerPermissionCatalog;
use App\Models\AiProviderProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OwnerWorkspacePermissionsAndOllamaConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_catalog_covers_every_source_react_workspace_and_registered_action(): void
    {
        $names = app(OwnerPermissionCatalog::class)->all();
        $this->assertContains('ai.configure', $names);
        $this->assertContains('ai.viewUsage', $names);
        $this->assertContains('diagnostics.view', $names);
        $this->assertContains('roles.view', $names);

        $source = file_get_contents(resource_path('js/core.ts'));
        preg_match_all("/permission:\\s*['\"]([^'\"]+)['\"]/", $source, $matches);
        foreach (array_unique($matches[1]) as $permission) {
            $this->assertContains($permission, $names, 'Missing compiled workspace permission '.$permission);
        }
        foreach (config('frontend_actions', []) as $action) {
            if (is_string($action['permission'] ?? null)) {
                $this->assertContains($action['permission'], $names);
            }
        }
    }

    public function test_existing_platform_owner_backfill_is_idempotent_and_never_grants_a_neighboring_tenant(): void
    {
        [$tenant, $owner] = $this->member('primary', ['tenant.view'], true);
        [$otherTenant, $otherOwner] = $this->member('other', ['tenant.view']);
        [$third, $outsider] = $this->member('third', ['tenant.view'], true, 'Reader');

        $migration = require database_path('migrations/2026_11_06_090000_backfill_platform_owner_workspace_permissions.php');
        $migration->up();
        $migration->up();

        foreach (['ai.configure', 'ai.viewUsage', 'diagnostics.view', 'backups.view', 'settings.manage'] as $name) {
            $this->assertSame(1, DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('permission_role.tenant_id', $tenant->id)
                ->where('permissions.name', $name)->count());
            $this->assertFalse(DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permission_role.tenant_id', [$otherTenant->id, $third->id])
                ->where('permissions.name', $name)->exists());
        }
        $this->actingAs($owner)->getJson('/tenants/primary/context')
            ->assertOk()->assertJsonFragment(['ai.configure']);
        $this->actingAs($otherOwner)->get('/tenants/other/settings/ai-providers')->assertForbidden();
        $this->actingAs($outsider)->get('/tenants/third/settings/ai-providers')->assertForbidden();
    }

    public function test_admin_can_store_ollama_public_https_model_and_encrypted_proxy_key_then_check_once(): void
    {
        [$tenant, $owner] = $this->member('alpha', ['tenant.view', 'settings.manage']);
        $payload = [
            'provider_key' => 'ollama',
            'display_name' => 'Ollama private',
            'endpoint' => 'https://ai.walidatiya.com/v1',
            'model_key' => 'qwen2.5:3b',
            'api_key' => 'CI-SECRET-ONLY',
            'enabled' => '1',
            'max_output_tokens' => 384,
        ];

        $this->actingAs($owner)->post('/tenants/alpha/settings/ai-providers', $payload)
            ->assertRedirect('/tenants/alpha/settings/ai-providers')
            ->assertSessionHas('status');

        app(TenantContext::class)->activate($tenant);
        $profile = AiProviderProfile::query()->where('provider_key', 'ollama')->firstOrFail();
        $this->assertSame('openai-compatible', $profile->adapter_key);
        $this->assertSame(1, $profile->max_attempts);
        $this->assertFalse($profile->automatic_failover);
        $this->assertSame('qwen2.5:3b', $profile->models()->firstOrFail()->model_key);
        $this->assertNotSame('CI-SECRET-ONLY', DB::table('tenant_secrets')->value('encrypted_value'));
        app(TenantContext::class)->forget();

        $this->actingAs($owner)->get('/tenants/alpha/settings/ai-providers')->assertOk()
            ->assertSee('Ollama private')
            ->assertSee('qwen2.5:3b')
            ->assertDontSee('CI-SECRET-ONLY');

        Http::fake(['ai.walidatiya.com/*' => Http::response(['data' => [['id' => 'qwen2.5:3b']]], 200)]);
        $this->actingAs($owner)->post('/tenants/alpha/settings/ai-providers/ollama/check')
            ->assertRedirect('/tenants/alpha/settings/ai-providers')
            ->assertSessionHas('status');
        Http::assertSentCount(1);
        app(TenantContext::class)->activate($tenant);
        $this->assertSame('READY', AiProviderProfile::query()->where('provider_key', 'ollama')->firstOrFail()->readiness_state->value);
        app(TenantContext::class)->forget();
    }

    public function test_hostile_endpoints_and_unauthorized_or_cross_tenant_provider_edits_are_blocked(): void
    {
        [, $owner] = $this->member('alpha', ['tenant.view', 'settings.manage']);
        [, $reader] = $this->member('beta', ['tenant.view']);
        $payload = [
            'provider_key' => 'ollama',
            'display_name' => 'Blocked host',
            'endpoint' => 'https://127.0.0.1/v1',
            'model_key' => 'test:1',
        ];
        $this->actingAs($owner)->post('/tenants/alpha/settings/ai-providers', $payload)
            ->assertSessionHasErrors('endpoint');
        $this->actingAs($reader)->post('/tenants/beta/settings/ai-providers', [
            ...$payload, 'endpoint' => 'https://ai.walidatiya.com/v1',
        ])->assertForbidden();
        $this->actingAs($owner)->post('/tenants/beta/settings/ai-providers', [
            ...$payload, 'endpoint' => 'https://ai.walidatiya.com/v1',
        ])->assertNotFound();
        $this->assertSame(0, DB::table('ai_provider_profiles')->count());
    }

    /**
     * @param  list<string>  $permissions
     * @return array{Tenant, User}
     */
    private function member(
        string $slug,
        array $permissions,
        bool $platformAdmin = false,
        string $roleName = 'Owner',
    ): array {
        $tenant = Tenant::query()->create(['name' => $slug, 'slug' => $slug]);
        $user = User::factory()->create(['platform_admin' => $platformAdmin]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return [$tenant, $user];
    }
}
