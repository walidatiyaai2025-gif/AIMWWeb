<?php

namespace Tests\Feature;

use App\Http\Controllers\AiProviderSettingsReadController;
use App\Models\AiProviderProfile;
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

class AiProviderSettingsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-58FABCCEDB';

    public function test_exact_canonical_operation_is_the_ai_provider_settings_route(): void
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
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/settings/ai-providers', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIProviderSettings.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_guarded_and_carries_operation_provenance(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-providers', 'GET'));

        $this->assertSame(
            AiProviderSettingsReadController::class,
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('tenant.settings.ai-providers', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_settings_manager_renders_real_tenant_provider_state_without_secret_material(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');
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
            'settings' => ['internal_marker' => 'DO-NOT-RENDER-SECRET-MATERIAL'],
        ]);
        $context->forget();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/settings/ai-providers')
            ->assertOk()
            ->assertSee('AI Provider Settings')
            ->assertSee('OpenAI Production')
            ->assertSee('gpt-test')
            ->assertSee('API credential')
            ->assertSee('Not configured')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('DO-NOT-RENDER-SECRET-MATERIAL')
            ->assertDontSee('<form', false);

        $this->assertSame('text/html; charset=utf-8', $response->headers->get('content-type'));
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->get('/tenants/alpha/settings/ai-providers')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view'], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/settings/ai-providers')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage'], 'Alpha Settings');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['settings.manage'], 'Beta Settings');

        $this->actingAs($alpha)->get('/tenants/beta/settings/ai-providers')->assertNotFound();
    }

    public function test_route_is_read_only_and_exposes_no_provider_mutation_surface(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');

        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-providers', ['provider_key' => 'openai'])
            ->assertMethodNotAllowed();
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
}
