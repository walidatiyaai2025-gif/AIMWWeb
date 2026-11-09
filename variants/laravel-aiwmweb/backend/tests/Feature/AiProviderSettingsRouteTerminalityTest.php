<?php

namespace Tests\Feature;

use App\AI\Platform\Enums\ProviderReadiness;
use App\AI\Platform\Services\ProviderSecretStore;
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
use Illuminate\Support\Facades\Http;
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
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame(
            'variants/laravel-aiwmweb/backend/tests/Feature/AiProviderSettingsRouteTerminalityTest.php',
            $operation['acceptance_test'],
        );
        $this->assertSame('explicit_route_api_contract', $operation['reconciliation']['evidence_mode']);
        $this->assertSame(
            '860095fc8322b913f17b472b41c44c1c17dcc5eb',
            $operation['reconciliation']['source_sha'],
        );
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/ai-provider-settings-route-terminality.json',
            $operation['reconciliation']['evidence_path'],
        );
        $this->assertContains('middleware:auth', $operation['reconciliation']['signals']);
        $this->assertContains('middleware:tenant.context', $operation['reconciliation']['signals']);
        $this->assertContains('authorization:TenantAuthorizer', $operation['reconciliation']['signals']);
        $this->assertContains(self::OPERATION_ID, $document['validation']['explicit_route_api_contract_terminals']);
        $this->assertSame(931, $document['totals']['total']);
        $this->assertSame(
            931,
            $document['totals']['terminal'] + $document['totals']['pending'] + $document['totals']['blocked'],
        );
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
            ->assertSee('name="endpoint"', false)
            ->assertSee('name="api_key"', false);

        $this->assertSame('text/html; charset=utf-8', $response->headers->get('content-type'));
    }

    public function test_provider_settings_uses_authenticated_bilingual_dashboard_shell_and_production_assets(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage'], 'Shell Manager');

        $previousLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->actingAs($user)
                ->get('/tenants/alpha/settings/ai-providers')
                ->assertOk()
                ->assertSee('<html lang="en" dir="ltr" data-mode="dark">', false)
                ->assertSee('class="app-shell provider-settings-shell"', false)
                ->assertSee('data-dashboard-shell="aiwmweb"', false)
                ->assertSee('class="sidebar"', false)
                ->assertSee('class="topbar"', false)
                ->assertSee('id="provider-settings-content"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('AI Provider Settings')
                ->assertSee('Back to Settings')
                ->assertSee('name="api_key"', false);

            app()->setLocale('ar');
            $this->actingAs($user)
                ->get('/tenants/alpha/settings/ai-providers')
                ->assertOk()
                ->assertSee('<html lang="ar" dir="rtl" data-mode="dark">', false)
                ->assertSee('data-dashboard-shell="aiwmweb"', false)
                ->assertSee('إعدادات مزودي الذكاء الاصطناعي')
                ->assertSee('مزودو الذكاء الاصطناعي')
                ->assertSee('العودة إلى الإعدادات')
                ->assertSee('حالة التشغيل')
                ->assertSee('name="api_key"', false);
        } finally {
            app()->setLocale($previousLocale);
        }

        $source = (string) file_get_contents(resource_path('views/ai/provider-settings.blade.php'));
        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringContainsString('@media (max-width: 700px)', $source);
        $this->assertStringContainsString('data-dashboard-shell="aiwmweb"', $source);
        $this->assertDatabaseCount('ai_provider_profiles', 0);
    }

    public function test_openai_compatible_ready_means_models_discovery_not_verified_generation_in_both_locales(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['settings.manage'], 'Model Discovery');
        $context = app(TenantContext::class);
        $context->activate($membership->tenant);

        $provider = AiProviderProfile::query()->create([
            'provider_key' => 'ollama',
            'adapter_key' => 'openai-compatible',
            'display_name' => 'Ollama Gateway',
            'endpoint' => 'https://ai.example.test/v1',
            'default_model' => 'qwen3:1.7b',
            'enabled' => true,
            'priority' => 5,
            'readiness_state' => ProviderReadiness::Ready,
            'readiness_checked_at' => now(),
        ]);
        $secret = 'sensitive-server-side-only-test-token';
        app(ProviderSecretStore::class)->put($provider, $secret);
        $context->forget();

        Http::fake();
        $previousLocale = app()->getLocale();
        try {
            app()->setLocale('en');
            $this->actingAs($user)
                ->get('/tenants/alpha/settings/ai-providers')
                ->assertOk()
                ->assertSee('data-provider-probe-scope="models-only"', false)
                ->assertSee('READY confirms model discovery, not successful content generation.')
                ->assertSee('data-provider-generation-status="unverified"', false)
                ->assertSee('POST /chat/completions, JSON output validity and cPanel Cron/worker health require separate authorized checks.')
                ->assertDontSee($secret);

            app()->setLocale('ar');
            $this->actingAs($user)
                ->get('/tenants/alpha/settings/ai-providers')
                ->assertOk()
                ->assertSee('ظهور READY يعني إمكانية اكتشاف النموذج، ولا يثبت نجاح توليد المحتوى.')
                ->assertSee('غير مختبر: يحتاج POST /chat/completions وصلاحية JSON')
                ->assertDontSee($secret);
        } finally {
            app()->setLocale($previousLocale);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_provider_profiles', 1);
        $this->assertSame(ProviderReadiness::Ready, $provider->fresh()->readiness_state);
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

    public function test_provider_mutation_requires_validated_fields_and_tenant_permission(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage'], 'Settings Manager');

        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-providers', ['provider_key' => 'openai'])
            ->assertSessionHasErrors(['display_name', 'endpoint', 'model_key']);
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
