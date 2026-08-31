<?php

namespace Tests\Feature;

use App\Http\Controllers\AiPromptTemplateRestoreController;
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

class AiPromptTemplateRestoreVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-D5FAAA34DD';

    public function test_exact_canonical_operation_is_the_pending_restore_visible_control_and_source_requires_append_only_restore(): void
    {
        $row = $this->canonicalRow(self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-prompts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor', $row['current_source']);
        $this->assertStringContainsString('Restore', $row['visible_control']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('PENDING', $row['migration_state']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor'));
        $this->assertStringContainsString('Restoring never rewrites history; it creates a new revision from the selected historical content.', $source);
        $this->assertStringContainsString('Disabled="@(version.Revision == _selected.Revision)"', $source);
        $this->assertStringContainsString('Restore revision r{revision} of {_selected.Key} as a new revision?', $source);
        $this->assertStringContainsString('PromptStore.Restore(_selected.Key, revision, AdministratorActor())', $source);
        $this->assertStringContainsString('ReloadPreservingMessage(restored.Key);', $source);
    }

    public function test_restore_control_is_real_confirmed_and_bound_to_an_explicit_tenant_mutation_route(): void
    {
        $route = Route::getRoutes()->match(Request::create(
            '/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore',
            'POST',
        ));

        $this->assertSame(AiPromptTemplateRestoreController::class, $route->getActionName());
        $this->assertSame('tenant.settings.ai-prompts.restore', $route->getName());
        $this->assertSame(['tenant', 'template', 'version'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $this->persistTwoRevisionTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('Restoring never rewrites history; it creates a new revision from the selected historical content.')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore', false)
            ->assertSee('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/2/restore', false)
            ->assertSee('Restore revision r1 of alpha.rewrite as a new revision?')
            ->assertSee('Restore revision r2 of alpha.rewrite as a new revision?')
            ->assertSee('data-canonical-operation="AIMW-AI-79AE29D6B3"', false)
            ->assertSee('data-canonical-operation="AIMW-AI-E1A964346F"', false)
            ->assertSee('disabled', false);
    }

    public function test_restore_replays_historical_snapshot_as_a_new_revision_preserves_history_and_audits_actor(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $template = $this->persistTwoRevisionTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Restored alpha.rewrite as revision r3.');

        $restored = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame(3, $restored->current_version);
        $this->assertSame('Original title', $restored->title);
        $this->assertSame('Original system prompt.', $restored->system_template);
        $this->assertSame('Original {{content}} prompt.', $restored->user_template);
        $this->assertFalse($restored->enabled);
        $this->assertSame($user->id, $restored->updated_by_user_id);

        $revisions = AiPromptRevision::query()->withoutGlobalScopes()
            ->where('ai_prompt_template_id', $template->id)
            ->orderBy('version')
            ->get();
        $this->assertCount(3, $revisions);
        $this->assertSame('created', $revisions[0]->change_type);
        $this->assertSame('updated', $revisions[1]->change_type);
        $this->assertSame('restored', $revisions[2]->change_type);
        $this->assertSame('Original {{content}} prompt.', $revisions[0]->snapshot['user_template']);
        $this->assertSame('Current {{content}} prompt.', $revisions[1]->snapshot['user_template']);
        $this->assertSame($revisions[0]->snapshot, $revisions[2]->snapshot);
        $this->assertSame($user->id, $revisions[2]->actor_user_id);

        $audit = AuditEvent::query()->withoutGlobalScopes()
            ->where('event', 'ai.prompt.changed')
            ->firstOrFail();
        $this->assertSame($alpha->id, $audit->tenant_id);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('alpha.rewrite', $audit->metadata['stable_key']);
        $this->assertSame(3, $audit->metadata['version']);
        $this->assertSame('restored', $audit->metadata['change_type']);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('Original title')
            ->assertSee('r3 · restored')
            ->assertSee('Original {{content}} prompt.');
    }

    public function test_restore_always_appends_a_new_revision_even_when_target_snapshot_matches_current_state(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $template = $this->persistTwoRevisionTemplate($alpha, $user, 'alpha.rewrite');

        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertRedirect('/tenants/alpha/settings/ai-prompts');
        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Restored alpha.rewrite as revision r4.');

        $fresh = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame(4, $fresh->current_version);

        $revisions = AiPromptRevision::query()->withoutGlobalScopes()
            ->where('ai_prompt_template_id', $template->id)
            ->orderBy('version')
            ->get();
        $this->assertCount(4, $revisions);
        $this->assertSame(['created', 'updated', 'restored', 'restored'], $revisions->pluck('change_type')->all());
        $this->assertSame($revisions[0]->snapshot, $revisions[2]->snapshot);
        $this->assertSame($revisions[0]->snapshot, $revisions[3]->snapshot);
        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('event', 'ai.prompt.changed')->count());
    }

    public function test_restore_fails_closed_for_guest_permissions_foreign_tenant_direct_key_missing_revision_current_revision_and_locked_builtin(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['settings.manage']);
        $alphaTemplate = $this->persistTwoRevisionTemplate($alpha, $user, 'alpha.rewrite');

        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->persistTwoRevisionTemplate($beta, $user, 'beta.secret');

        $this->get('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertStatus(405);
        $this->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->post('/tenants/limited/settings/ai-prompts/alpha.rewrite/revisions/1/restore')
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/tenants/beta/settings/ai-prompts/beta.secret/revisions/1/restore')
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-prompts/beta.secret/revisions/1/restore')
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/99/restore')
            ->assertNotFound();
        $this->actingAs($user)
            ->post('/tenants/alpha/settings/ai-prompts/alpha.rewrite/revisions/2/restore')
            ->assertStatus(409);

        $locked = $this->persistTwoRevisionTemplate($alpha, $user, 'alpha.locked', true, false);
        $this->actingAs($user)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts/alpha.locked/revisions/1/restore')
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHasErrors('stable_key')
            ->assertSessionMissing('status');

        $alphaFresh = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($alphaTemplate->id);
        $lockedFresh = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($locked->id);
        $this->assertSame(2, $alphaFresh->current_version);
        $this->assertSame('Current {{content}} prompt.', $alphaFresh->user_template);
        $this->assertSame(2, $lockedFresh->current_version);
        $this->assertSame('Current {{content}} prompt.', $lockedFresh->user_template);
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScopes()->where('event', 'ai.prompt.changed')->count());
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
        $role = Role::query()->create(['name' => "ai-prompt-restore-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function persistTwoRevisionTemplate(
        Tenant $tenant,
        User $actor,
        string $stableKey,
        bool $isBuiltin = false,
        bool $allowTenantOverride = true,
    ): AiPromptTemplate {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $original = [
            'domain' => 'content',
            'title' => 'Original title',
            'system_template' => 'Original system prompt.',
            'user_template' => 'Original {{content}} prompt.',
            'variables' => ['content'],
            'output_schema' => ['type' => 'object'],
            'enabled' => false,
        ];
        $current = [
            'domain' => 'content',
            'title' => 'Current title',
            'system_template' => 'Current system prompt.',
            'user_template' => 'Current {{content}} prompt.',
            'variables' => ['content'],
            'output_schema' => ['type' => 'object'],
            'enabled' => true,
        ];

        $template = AiPromptTemplate::query()->create([
            'stable_key' => $stableKey,
            ...$current,
            'is_builtin' => $isBuiltin,
            'allow_tenant_override' => $allowTenantOverride,
            'current_version' => 2,
            'updated_by_user_id' => $actor->id,
        ]);

        AiPromptRevision::query()->create([
            'ai_prompt_template_id' => $template->id,
            'version' => 1,
            'snapshot' => $original,
            'change_type' => 'created',
            'actor_user_id' => $actor->id,
            'created_at' => now()->subMinute(),
        ]);
        AiPromptRevision::query()->create([
            'ai_prompt_template_id' => $template->id,
            'version' => 2,
            'snapshot' => $current,
            'change_type' => 'updated',
            'actor_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $context->forget();

        return $template;
    }
}
