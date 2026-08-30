<?php

namespace Tests\Feature;

use App\Http\Controllers\AiPromptTemplatesReadController;
use App\Models\AiPromptRevision;
use App\Models\AiPromptTemplate;
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

class AiPromptTemplatesRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_reconciliation_row_is_the_pending_ai_prompt_templates_route(): void
    {
        $row = $this->canonicalRow('AIMW-AI-1E33F01F4E');

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('/settings/ai-prompts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor', $row['current_source']);
        $this->assertSame('Open/render route', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_ai_prompt_templates_is_an_explicit_guarded_route_without_direct_id_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts', 'GET'));

        $this->assertSame(AiPromptTemplatesReadController::class, $route->getActionName());
        $this->assertSame('tenant.settings.ai-prompts', $route->getName());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/tenants/alpha/settings/ai-prompts')->assertRedirect('/login');
    }

    public function test_settings_manager_reads_only_authoritative_tenant_templates_and_revision_history(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $this->persistTemplate(
            $alpha,
            $user,
            'alpha.rewrite',
            'Alpha rewrite',
            'Rewrite {{content}} safely.',
            2,
            'updated',
        );

        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->persistTemplate(
            $beta,
            $user,
            'beta.secret',
            'Beta secret prompt',
            'Never expose this tenant prompt.',
            7,
            'updated',
        );

        $templateCount = AiPromptTemplate::query()->withoutGlobalScopes()->count();
        $revisionCount = AiPromptRevision::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts');

        $response->assertOk()
            ->assertSee('AI Prompt Templates')
            ->assertSee('Alpha rewrite')
            ->assertSee('alpha.rewrite')
            ->assertSee('Rewrite {{content}} safely.')
            ->assertSee('r2')
            ->assertSee('updated')
            ->assertDontSee('beta.secret')
            ->assertDontSee('Beta secret prompt')
            ->assertDontSee('Never expose this tenant prompt.');

        $this->assertSame($templateCount, AiPromptTemplate::query()->withoutGlobalScopes()->count());
        $this->assertSame($revisionCount, AiPromptRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_empty_registry_is_truthful_and_read_has_no_seed_side_effect(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $this->assertDatabaseCount('ai_prompt_templates', 0);
        $this->assertDatabaseCount('ai_prompt_revisions', 0);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('0 persisted templates')
            ->assertSee('No AI prompt templates have been persisted for this tenant.');

        $this->assertDatabaseCount('ai_prompt_templates', 0);
        $this->assertDatabaseCount('ai_prompt_revisions', 0);
    }

    public function test_route_fails_closed_for_missing_settings_permission_and_foreign_tenant(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')->assertForbidden();
        $this->actingAs($user)->get('/tenants/foreign/settings/ai-prompts')->assertNotFound();
    }

    private function canonicalRow(string $operationId): ?array
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($payload['operations'])->firstWhere('operation_id', $operationId);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-prompts-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function persistTemplate(
        Tenant $tenant,
        User $actor,
        string $stableKey,
        string $title,
        string $userTemplate,
        int $version,
        string $changeType,
    ): void {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $template = AiPromptTemplate::query()->create([
            'stable_key' => $stableKey,
            'domain' => 'content',
            'title' => $title,
            'system_template' => 'System safety prompt.',
            'user_template' => $userTemplate,
            'variables' => ['content'],
            'output_schema' => ['type' => 'object'],
            'enabled' => true,
            'is_builtin' => false,
            'allow_tenant_override' => true,
            'current_version' => $version,
            'updated_by_user_id' => $actor->id,
        ]);

        AiPromptRevision::query()->create([
            'ai_prompt_template_id' => $template->id,
            'version' => $version,
            'snapshot' => [
                'domain' => 'content',
                'title' => $title,
                'system_template' => 'System safety prompt.',
                'user_template' => $userTemplate,
                'variables' => ['content'],
                'output_schema' => ['type' => 'object'],
                'enabled' => true,
            ],
            'change_type' => $changeType,
            'actor_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $context->forget();
    }
}
