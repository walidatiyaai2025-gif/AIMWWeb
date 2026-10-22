<?php

namespace Tests\Feature;

use App\Models\AiProviderProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderSettingsBackToSettingsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-3B59F172AB';

    public function test_canonical_reconciliation_row_is_the_ai_provider_back_to_settings_control(): void
    {
        $row = $this->canonicalRow(self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-providers', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor', $row['current_source']);
        $this->assertStringContainsString('settings', strtolower((string) $row['visible_control']));
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertContains($row['migration_state'], ['PENDING', 'ADAPTED']);
    }

    public function test_settings_manager_gets_real_tenant_derived_back_to_settings_control_without_provider_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $providerCount = AiProviderProfile::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-providers');

        $response->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('aria-label="Back to Settings"', false)
            ->assertSee('href="/tenants/alpha/settings"', false)
            ->assertSee('← Settings', false);

        $this->withoutVite();
        $this->actingAs($user)->get('/tenants/alpha/settings')->assertOk();

        $this->assertSame($providerCount, AiProviderProfile::query()->withoutGlobalScopes()->count());
    }

    public function test_control_stays_fail_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        $this->get('/tenants/alpha/settings/ai-providers')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($limited)->get('/tenants/alpha/settings/ai-providers')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/beta/settings/ai-providers')->assertNotFound();
        $this->actingAs($limited)->get('/tenants/beta/settings')->assertNotFound();
    }

    public function test_control_target_cannot_be_redirected_to_a_caller_supplied_tenant_or_resource(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-providers');

        $response->assertOk()
            ->assertSee('href="/tenants/alpha/settings"', false)
            ->assertDontSee('href="/tenants/beta/settings"', false)
            ->assertDontSee('provider=', false)
            ->assertDontSee('site=', false)
            ->assertDontSee('user=', false);
    }

    private function canonicalRow(string $operationId): ?array
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($payload['operations'])->firstWhere('operation_id', $operationId);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-provider-back-settings-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
