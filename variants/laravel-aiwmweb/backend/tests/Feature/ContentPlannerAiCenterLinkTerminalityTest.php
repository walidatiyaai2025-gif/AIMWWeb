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

final class ContentPlannerAiCenterLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-CAFE798AA3';

    public function test_source_and_laravel_control_bind_exact_ai_center_navigation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor'));
        $control = (string) file_get_contents(resource_path('js/content-planner-ai-center-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString('<a class="btn" href="/ai-center">✦ AI Center</a>', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/ai-center:/ai-center',
            $control,
        );
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, \'/ai-center\')', $control);
        $this->assertStringContainsString('route.key === \'content-planner\'', $app);
        $this->assertStringContainsString('ContentPlannerAiCenterLinkControl context={context}', $app);
        $this->assertStringContainsString('r(\'ai-center\', \'/ai-center\'', $core);
        $this->assertStringContainsString('permission: \'ai.use\'', $core);
        $this->assertStringNotContainsString('to="/ai-center"', $control);
    }

    public function test_destination_is_the_existing_guarded_ai_center_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/ai-center', 'GET'));

        $this->assertSame('canonical.workspace.ai-center', $route->getName());
        $this->assertSame('tenant.view,ai.use', $route->defaults['workspace_permissions'] ?? null);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_authorized_navigation_is_tenant_scoped_and_non_mutating(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'ai.use']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($user)->get('/tenants/alpha/content-planner')->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/ai-center')->assertOk();
        $this->actingAs($user)->get('/tenants/foreign/ai-center')->assertNotFound();

        $control = (string) file_get_contents(resource_path('js/content-planner-ai-center-link-control.tsx'));
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('method:', $control);
    }

    public function test_missing_source_or_destination_permission_fails_closed(): void
    {
        $this->withoutVite();

        $noAi = User::factory()->create();
        $this->membership($noAi, 'no-ai', ['tenant.view', 'content.view']);
        $this->actingAs($noAi)->get('/tenants/no-ai/content-planner')->assertOk();
        $this->actingAs($noAi)->get('/tenants/no-ai/ai-center')->assertForbidden();

        $noPlanner = User::factory()->create();
        $this->membership($noPlanner, 'no-planner', ['tenant.view', 'ai.use']);
        $this->actingAs($noPlanner)->get('/tenants/no-planner/content-planner')->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "content-planner-ai-center-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
