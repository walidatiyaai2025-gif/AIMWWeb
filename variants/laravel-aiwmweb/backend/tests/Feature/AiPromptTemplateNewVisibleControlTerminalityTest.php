<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPromptTemplateNewVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_operation_matches_the_source_new_template_state_transition(): void
    {
        $row = $this->canonicalRow('AIMW-AI-825B2F5A38');

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-prompts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor', $row['current_source']);
        $this->assertSame('ADAPTED', $row['migration_state']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor'));
        $this->assertStringContainsString('OnClick="NewTemplateClicked"', $source);
        $this->assertStringContainsString('private void NewTemplateClicked(MouseEventArgs _) => NewTemplate();', $source);
        $this->assertStringContainsString('private void NewTemplate()', $source);
        $this->assertStringContainsString('_selectedKey = string.Empty;', $source);
        $this->assertStringContainsString('_isNew = true;', $source);
        $this->assertStringContainsString('_history.Clear();', $source);
        $this->assertStringContainsString('_message = string.Empty;', $source);
    }

    public function test_settings_manager_receives_a_real_state_only_new_template_control_without_server_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $templateCount = \DB::table('ai_prompt_templates')->count();
        $revisionCount = \DB::table('ai_prompt_revisions')->count();

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts');

        $response->assertOk()
            ->assertSee('data-canonical-operation="AIMW-AI-825B2F5A38"', false)
            ->assertSee('data-ai-prompt-new-template', false)
            ->assertSee('aria-controls="new-template-editor"', false)
            ->assertSee('id="new-template-editor"', false)
            ->assertSee('data-ai-prompt-new-editor', false)
            ->assertSee('No data is persisted until the separate Save operation runs.')
            ->assertSee('/js/ai-prompt-new-template.js', false);

        $this->assertSame($templateCount, \DB::table('ai_prompt_templates')->count());
        $this->assertSame($revisionCount, \DB::table('ai_prompt_revisions')->count());
    }

    public function test_production_browser_handler_is_a_truthful_reset_without_request_or_navigation(): void
    {
        $script = file_get_contents(public_path('js/ai-prompt-new-template.js'));

        $this->assertStringContainsString('wireAiPromptNewTemplate', $script);
        $this->assertStringContainsString("key.value = '';", $script);
        $this->assertStringContainsString("title.value = '';", $script);
        $this->assertStringContainsString("systemPrompt.value = '';", $script);
        $this->assertStringContainsString("userPrompt.value = '';", $script);
        $this->assertStringContainsString('enabled.checked = true;', $script);
        $this->assertStringContainsString("editor.dataset.state = 'new';", $script);
        $this->assertStringContainsString('nothing has been persisted', $script);
        $this->assertStringNotContainsString('fetch(', $script);
        $this->assertStringNotContainsString('XMLHttpRequest', $script);
        $this->assertStringNotContainsString('location.href', $script);
        $this->assertStringNotContainsString('form.submit', $script);
    }

    public function test_control_inherits_route_auth_permission_and_tenant_fail_closed_semantics(): void
    {
        $this->get('/tenants/alpha/settings/ai-prompts')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/settings/ai-prompts')->assertForbidden();

        $manager = User::factory()->create();
        $this->membership($manager, 'alpha', ['settings.manage']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($manager)->get('/tenants/foreign/settings/ai-prompts')->assertNotFound();
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
        $role = Role::query()->create(['name' => "ai-prompt-new-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
