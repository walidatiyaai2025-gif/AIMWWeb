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

final class AiProviderRuntimeAnchorTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-44DD850CE4';

    public function test_exact_canonical_operation_is_generator_backed_runtime_section_navigation_candidate(): void
    {
        $document = $this->reconciliation();
        $row = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-providers', $row['route_screen']);
        $this->assertSame('#runtime -> #runtime', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertContains($row['migration_state'], ['PENDING', 'ADAPTED']);

        if ($row['migration_state'] === 'ADAPTED') {
            $this->assertSame(
                'variants/laravel-aiwmweb/backend/resources/views/ai/provider-settings.blade.php',
                $row['laravel_destination'],
            );
            $this->assertSame(
                'variants/laravel-aiwmweb/backend/tests/Feature/AiProviderRuntimeAnchorTerminalityTest.php',
                $row['acceptance_test'],
            );
            $this->assertSame('focused_closure_contract', $row['reconciliation']['evidence_mode']);
            $this->assertSame(
                'variants/laravel-aiwmweb/docs/closure-evidence/ai-provider-runtime-anchor-terminality.json',
                $row['reconciliation']['evidence_path'],
            );
            $this->assertContains(self::OPERATION_ID, $document['validation']['focused_closure_contract_terminals']);
        }

        $this->assertSame(931, $document['totals']['total']);
        $this->assertSame(
            $document['totals']['total'],
            $document['totals']['terminal'] + $document['totals']['pending'] + $document['totals']['blocked'],
        );
        $this->assertTrue((bool) ($document['validation']['passed'] ?? false));
    }

    public function test_settings_manager_gets_exact_non_mutating_runtime_anchor_and_target_section(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage']);
        $context = app(TenantContext::class);
        $context->activate($membership->tenant);

        AiProviderProfile::query()->create([
            'provider_key' => 'openai',
            'adapter_key' => 'openai',
            'display_name' => 'OpenAI Production',
            'endpoint' => 'https://provider.example.test/v1',
            'default_model' => 'gpt-test',
            'enabled' => true,
            'priority' => 5,
            'settings' => ['api_key' => 'DO-NOT-RENDER-RUNTIME-SECRET'],
        ]);
        $context->forget();

        $this->actingAs($user)
            ->get('/tenants/alpha/settings/ai-providers')
            ->assertOk()
            ->assertSee('href="#runtime"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Runtime behavior')
            ->assertSee('id="runtime"', false)
            ->assertSee('aria-labelledby="runtime-behavior"', false)
            ->assertSee('This section is read-only')
            ->assertDontSee('DO-NOT-RENDER-RUNTIME-SECRET')
            ->assertDontSee('<form', false);
    }

    public function test_runtime_anchor_inherits_fail_closed_route_security_and_cannot_select_another_tenant(): void
    {
        $this->get('/tenants/alpha/settings/ai-providers')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/settings/ai-providers')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);

        $this->actingAs($alpha)->get('/tenants/beta/settings/ai-providers')->assertNotFound();

        $response = $this->actingAs($alpha)->get('/tenants/alpha/settings/ai-providers');
        $response->assertOk()
            ->assertSee('href="#runtime"', false)
            ->assertDontSee('/tenants/beta/settings/ai-providers#runtime', false)
            ->assertDontSee('provider_id=', false)
            ->assertDontSee('tenant=', false);
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-provider-runtime-anchor-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
