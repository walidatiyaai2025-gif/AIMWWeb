<?php

namespace Tests\Feature;

use App\Http\Controllers\AiPromptTemplateSaveController;
use App\Models\AiPromptRevision;
use App\Models\AiPromptTemplate;
use App\Models\AuditEvent;
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

class AiPromptTemplateSaveVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_operation_is_the_pending_save_visible_control_and_source_is_a_real_mutation(): void
    {
        $row = $this->canonicalRow('AIMW-AI-79AE29D6B3');

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-prompts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor', $row['current_source']);
        $this->assertStringContainsString('SaveClicked', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('PENDING', $row['migration_state']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor'));
        $this->assertStringContainsString('private void SaveClicked(MouseEventArgs _) => Save();', $source);
        $this->assertStringContainsString('PromptStore.Save(new AIPromptTemplateInput', $source);
        $this->assertStringContainsString('ReloadPreservingMessage(saved.Key);', $source);
    }

    public function test_save_control_is_real_guarded_and_bound_to_an_explicit_mutation_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts/alpha.rewrite', 'PATCH'));

        $this->assertSame(AiPromptTemplateSaveController::class, $route->getActionName());
        $this->assertSame('tenant.settings.ai-prompts.save', $route->getName());
        $this->assertSame(['tenant', 'template'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $this->persistTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('data-canonical-operation="AIMW-AI-79AE29D6B3"', false)
            ->assertSee('/tenants/alpha/settings/ai-prompts/alpha.rewrite', false)
            ->assertSee('The stable key is immutable.')
            ->assertSee('Save');
    }

    public function test_save_persists_real_change_creates_revision_and_audit_then_authoritatively_rereads(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $template = $this->persistTemplate($alpha, $user, 'alpha.rewrite');

        $response = $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->patch('/tenants/alpha/settings/ai-prompts/alpha.rewrite', [
                '_prompt_key' => 'alpha.rewrite',
                'stable_key' => 'attempted-key-change',
                'title' => 'Alpha rewrite updated',
                'system_template' => 'Updated system safety prompt.',
                'user_template' => 'Rewrite {{content}} with the new persisted contract.',
                'enabled' => '1',
            ]);

        $response->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Saved alpha.rewrite as revision r2.');

        $saved = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame('alpha.rewrite', $saved->stable_key);
        $this->assertSame('Alpha rewrite updated', $saved->title);
        $this->assertSame('Updated system safety prompt.', $saved->system_template);
        $this->assertSame('Rewrite {{content}} with the new persisted contract.', $saved->user_template);
        $this->assertSame(2, $saved->current_version);
        $this->assertSame($user->id, $saved->updated_by_user_id);

        $revisions = AiPromptRevision::query()->withoutGlobalScopes()
            ->where('ai_prompt_template_id', $template->id)
            ->orderBy('version')
            ->get();
        $this->assertCount(2, $revisions);
        $this->assertSame(2, $revisions->last()->version);
        $this->assertSame('updated', $revisions->last()->change_type);
        $this->assertSame('Rewrite {{content}} with the new persisted contract.', $revisions->last()->snapshot['user_template']);

        $audit = AuditEvent::query()->withoutGlobalScopes()
            ->where('event', 'ai.prompt.changed')
            ->firstOrFail();
        $this->assertSame($alpha->id, $audit->tenant_id);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('alpha.rewrite', $audit->metadata['stable_key']);
        $this->assertSame(2, $audit->metadata['version']);
        $this->assertSame('updated', $audit->metadata['change_type']);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('Alpha rewrite updated')
            ->assertSee('Rewrite {{content}} with the new persisted contract.')
            ->assertSee('r2 · updated');
    }

    public function test_unchanged_save_is_idempotent_and_does_not_fabricate_revision_or_audit(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $template = $this->persistTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)->patch('/tenants/alpha/settings/ai-prompts/alpha.rewrite', [
            '_prompt_key' => 'alpha.rewrite',
            'title' => 'Alpha rewrite',
            'system_template' => 'System safety prompt.',
            'user_template' => 'Rewrite {{content}} safely.',
            'enabled' => '1',
        ])->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Saved alpha.rewrite as revision r1.');

        $saved = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame(1, $saved->current_version);
        $this->assertSame(
            1,
            AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $template->id)->count(),
        );
        $this->assertSame(
            0,
            AuditEvent::query()->withoutGlobalScopes()->where('event', 'ai.prompt.changed')->count(),
        );
    }

    public function test_validation_failure_and_locked_builtin_never_report_fake_success_or_mutate(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $template = $this->persistTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->patch('/tenants/alpha/settings/ai-prompts/alpha.rewrite', [
                '_prompt_key' => 'alpha.rewrite',
                'title' => 'Invalid attempt',
                'system_template' => 'System safety prompt.',
                'user_template' => '',
                'enabled' => '1',
            ])
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHasErrors('user_template')
            ->assertSessionMissing('status');

        $unchanged = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame('Alpha rewrite', $unchanged->title);
        $this->assertSame(1, $unchanged->current_version);
        $this->assertSame(1, AiPromptRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->count());

        $locked = $this->persistTemplate($alpha, $user, 'alpha.locked', true, false);
        $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->patch('/tenants/alpha/settings/ai-prompts/alpha.locked', [
                '_prompt_key' => 'alpha.locked',
                'title' => 'Locked changed',
                'system_template' => 'System safety prompt.',
                'user_template' => 'Changed locked prompt.',
                'enabled' => '1',
            ])
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHasErrors('stable_key')
            ->assertSessionMissing('status');

        $lockedFresh = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($locked->id);
        $this->assertSame('Alpha rewrite', $lockedFresh->title);
        $this->assertSame(1, $lockedFresh->current_version);
        $this->assertSame(2, AiPromptRevision::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->count());
    }

    public function test_save_fails_closed_for_guest_missing_permission_foreign_tenant_and_cross_tenant_direct_key(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $this->persistTemplate($alpha, $user, 'alpha.rewrite');

        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->persistTemplate($beta, $user, 'beta.secret');

        $payload = [
            '_prompt_key' => 'alpha.rewrite',
            'title' => 'Attempted update',
            'system_template' => 'System safety prompt.',
            'user_template' => 'Attempted update.',
            'enabled' => '1',
        ];

        $this->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite', $payload)
            ->assertStatus(405);
        $this->patch('/tenants/alpha/settings/ai-prompts/alpha.rewrite', $payload)
            ->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->patch('/tenants/limited/settings/ai-prompts/alpha.rewrite', $payload)
            ->assertForbidden();

        $this->actingAs($user)
            ->patch('/tenants/beta/settings/ai-prompts/beta.secret', $payload)
            ->assertNotFound();

        $this->actingAs($user)
            ->patch('/tenants/alpha/settings/ai-prompts/beta.secret', $payload)
            ->assertNotFound();

        $alphaFresh = AiPromptTemplate::query()->withoutGlobalScopes()->where('stable_key', 'alpha.rewrite')->firstOrFail();
        $betaFresh = AiPromptTemplate::query()->withoutGlobalScopes()->where('stable_key', 'beta.secret')->firstOrFail();
        $this->assertSame('Alpha rewrite', $alphaFresh->title);
        $this->assertSame('Alpha rewrite', $betaFresh->title);
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->count());
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
        $role = Role::query()->create(['name' => "ai-prompt-save-{$slug}-{$user->id}"]);
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
        bool $isBuiltin = false,
        bool $allowTenantOverride = true,
    ): AiPromptTemplate {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $template = AiPromptTemplate::query()->create([
            'stable_key' => $stableKey,
            'domain' => 'content',
            'title' => 'Alpha rewrite',
            'system_template' => 'System safety prompt.',
            'user_template' => 'Rewrite {{content}} safely.',
            'variables' => ['content'],
            'output_schema' => ['type' => 'object'],
            'enabled' => true,
            'is_builtin' => $isBuiltin,
            'allow_tenant_override' => $allowTenantOverride,
            'current_version' => 1,
            'updated_by_user_id' => $actor->id,
        ]);

        AiPromptRevision::query()->create([
            'ai_prompt_template_id' => $template->id,
            'version' => 1,
            'snapshot' => [
                'domain' => 'content',
                'title' => 'Alpha rewrite',
                'system_template' => 'System safety prompt.',
                'user_template' => 'Rewrite {{content}} safely.',
                'variables' => ['content'],
                'output_schema' => ['type' => 'object'],
                'enabled' => true,
            ],
            'change_type' => 'created',
            'actor_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $context->forget();

        return $template;
    }
}
