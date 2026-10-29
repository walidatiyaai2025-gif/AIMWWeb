<?php

namespace Tests\Feature;

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

final class ModuleWorkspaceCanonicalDestinationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-7F2BC55FF4';

    public function test_exact_canonical_operation_is_the_module_workspace_destination_anchor(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/{Key}', $operation['route_screen']);
        $this->assertSame('@destination -> @destination', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'));
        $this->assertStringContainsString('TryGetCanonicalDestination(out var destination)', $source);
        $this->assertStringContainsString('href="@destination"', $source);
        $this->assertStringContainsString('"approvals" => "/approvals"', $source);
        $this->assertStringContainsString('"ai-providers" => "/settings/ai-providers"', $source);
        $this->assertStringContainsString('"prompts" => "/settings/ai-prompts"', $source);
    }

    public function test_laravel_adapts_the_source_destination_map_to_real_tenant_qualified_workspace_links(): void
    {
        $component = (string) file_get_contents(resource_path('js/ai-workspace-hub.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('data-module-workspace-canonical-destination-operation', $component);
        $this->assertStringContainsString("key: 'ai-providers'", $component);
        $this->assertStringContainsString("path: '/settings/ai-providers'", $component);
        $this->assertStringContainsString("key: 'ai-prompts'", $component);
        $this->assertStringContainsString("path: '/settings/ai-prompts'", $component);
        $this->assertStringContainsString("key: 'approvals'", $component);
        $this->assertStringContainsString("path: '/approvals'", $component);
        $this->assertStringContainsString('encodeURIComponent(tenant)', $component);
        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('apiRequest(', $component);
    }

    public function test_destination_routes_are_authenticated_tenant_scoped_and_permission_guarded(): void
    {
        $providers = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-providers', 'GET'));
        $prompts = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts', 'GET'));
        $approvals = Route::getRoutes()->getByName('canonical.alias.approvals');

        foreach ([$providers, $prompts, $approvals] as $route) {
            $this->assertNotNull($route);
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('tenant.context', $route->gatherMiddleware());
        }

        $this->assertSame('tenant.settings.ai-providers', $providers->getName());
        $this->assertSame('tenant.settings.ai-prompts', $prompts->getName());
        $this->assertSame('tenant.view,approvals.view', $approvals->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/approvals', $approvals->defaults['workspace_target'] ?? null);

        $providerController = (string) file_get_contents(app_path('Http/Controllers/AiProviderSettingsReadController.php'));
        $promptController = (string) file_get_contents(app_path('Http/Controllers/AiPromptTemplatesReadController.php'));
        $this->assertStringContainsString("authorize('settings.manage')", $providerController);
        $this->assertStringContainsString("authorize('settings.manage')", $promptController);
    }

    public function test_missing_permission_and_foreign_tenant_destinations_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)->get('/tenants/limited/settings/ai-providers')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/settings/ai-prompts')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/approvals')->assertForbidden();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'settings.manage', 'approvals.view']);
        Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);

        foreach ([
            '/tenants/beta/settings/ai-providers',
            '/tenants/beta/settings/ai-prompts',
            '/tenants/beta/approvals',
        ] as $path) {
            $this->actingAs($alphaUser)->get($path)->assertNotFound();
        }
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
        $role = Role::query()->create(['name' => 'module-canonical-destination-'.$slug.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
