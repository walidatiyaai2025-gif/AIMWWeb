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

class AiWorkspaceCardNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-A746A1C3EB';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_canonical_operation_is_the_workspace_card_navigation_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/content-workspace | /seo-workspace | /ai-workspace | /operations-workspace', $operation['route_screen']);
        $this->assertSame('@item.Href -> @item.Href', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/WorkspaceHub.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $component = (string) file_get_contents(resource_path('js/ai-workspace-hub.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('data-canonical-operation={AI_WORKSPACE_CARD_OPERATION_ID}', $component);
    }

    public function test_destination_guards_match_the_permissions_used_to_expose_cards(): void
    {
        $aiCenter = Route::getRoutes()->getByName('canonical.workspace.ai-center');
        $approvals = Route::getRoutes()->getByName('canonical.alias.approvals');
        $providers = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-providers', 'GET'));
        $prompts = Route::getRoutes()->getByName('tenant.settings.ai-prompts');

        $this->assertNotNull($aiCenter);
        $this->assertSame('tenant.view,ai.use', $aiCenter->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $aiCenter->gatherMiddleware());
        $this->assertContains('tenant.context', $aiCenter->gatherMiddleware());

        $this->assertNotNull($approvals);
        $this->assertSame('tenant.view,approvals.view', $approvals->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $approvals->gatherMiddleware());
        $this->assertContains('tenant.context', $approvals->gatherMiddleware());

        $this->assertContains('auth', $providers->gatherMiddleware());
        $this->assertContains('tenant.context', $providers->gatherMiddleware());
        $this->assertNotNull($prompts);
        $this->assertContains('auth', $prompts->gatherMiddleware());
        $this->assertContains('tenant.context', $prompts->gatherMiddleware());

        $providerController = (string) file_get_contents(app_path('Http/Controllers/AiProviderSettingsReadController.php'));
        $promptController = (string) file_get_contents(app_path('Http/Controllers/AiPromptTemplatesReadController.php'));
        $this->assertStringContainsString("authorize('settings.manage')", $providerController);
        $this->assertStringContainsString("authorize('settings.manage')", $promptController);
    }

    public function test_hub_only_member_cannot_open_any_destination_without_its_permission(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);

        $this->actingAs($user)->get('/tenants/alpha/ai-workspace')->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/ai-center')->assertForbidden();
        $this->actingAs($user)->get('/tenants/alpha/settings/ai-providers')->assertForbidden();
        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')->assertForbidden();
        $this->actingAs($user)->get('/tenants/alpha/approvals')->assertForbidden();
    }

    public function test_cross_tenant_navigation_targets_fail_closed_and_control_has_no_direct_resource_id(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'ai.use', 'settings.manage', 'approvals.view']);
        Tenant::query()->create(['slug' => 'beta', 'name' => 'Beta']);

        foreach ([
            '/tenants/beta/ai-workspace',
            '/tenants/beta/ai-center',
            '/tenants/beta/settings/ai-providers',
            '/tenants/beta/settings/ai-prompts',
            '/tenants/beta/approvals',
        ] as $path) {
            $this->actingAs($alphaUser)->get($path)->assertNotFound();
        }

        $component = (string) file_get_contents(resource_path('js/ai-workspace-hub.tsx'));
        $this->assertStringContainsString('encodeURIComponent(tenant)', $component);
        $this->assertStringNotContainsString('{site}', $component);
        $this->assertStringNotContainsString('{user}', $component);
        $this->assertStringNotContainsString('{membership}', $component);
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
        $role = Role::query()->create(['name' => 'ai-workspace-cards-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
