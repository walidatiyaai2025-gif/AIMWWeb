<?php

namespace Tests\Feature;

use App\Http\Controllers\AiPromptTemplateSaveController;
use App\Http\Controllers\AiPromptTemplatesReadController;
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

final class SettingsAiPromptsLinkVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-0D4D60320B';

    public function test_exact_canonical_operation_is_the_pending_settings_ai_prompts_link(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/settings', $operation['route_screen']);
        $this->assertSame('/settings/ai-prompts -> /settings/ai-prompts', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Settings.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Settings.razor'));
        $frontend = file_get_contents(resource_path('js/settings-ai-prompts-link-control.tsx'));
        $this->assertStringContainsString('<AuthorizeView Roles="Administrator">', $source);
        $this->assertStringContainsString('href="/settings/ai-prompts"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("context.permissions.includes('settings.manage')", $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/settings/ai-prompts')", $frontend);
    }

    public function test_ai_prompt_templates_destination_uses_existing_tenant_scoped_authorities(): void
    {
        $readRoute = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts', 'GET'));
        $saveRoute = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts/example', 'PATCH'));

        $this->assertSame(AiPromptTemplatesReadController::class, ltrim($readRoute->getActionName(), '\\'));
        $this->assertSame(AiPromptTemplateSaveController::class, ltrim($saveRoute->getActionName(), '\\'));
        $this->assertContains('auth', $readRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $readRoute->gatherMiddleware());
        $this->assertContains('auth', $saveRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $saveRoute->gatherMiddleware());
        $this->assertSame('tenant.settings.ai-prompts', $readRoute->getName());
        $this->assertSame('tenant.settings.ai-prompts.save', $saveRoute->getName());
    }

    public function test_settings_manager_can_open_real_prompt_templates_surface(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'settings.manage']);

        $this->actingAs($user)
            ->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('AI Prompt Templates')
            ->assertSee('No AI prompt templates have been persisted for this tenant.');
    }

    public function test_foreign_tenant_ai_prompt_templates_destination_fails_closed_with_404(): void
    {
        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'settings.manage']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'settings.manage']);

        $this->actingAs($alpha)
            ->get('/tenants/beta/settings/ai-prompts')
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
        $role = Role::query()->create(['name' => "settings-ai-prompts-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
