<?php

namespace Tests\Feature;

use App\Http\Controllers\AiCenterReadController;
use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\AiPromptTemplate;
use App\Models\AiUsageRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCenterRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_ai_center_route_is_explicit_guarded_and_backed_by_a_real_read_contract(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', 'AIMW-AI-82F795EE67');

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('explicit_route_api_contract', $operation['reconciliation']['evidence_mode']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('/ai-center', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertStringEndsWith('AICenter.razor', $operation['current_source']);
        $this->assertFalse($operation['mutation']);
        $this->assertTrue($operation['tenant_owned']);

        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/ai-center', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($workspace->getActionName(), '\\'));
        $this->assertContains('web', $workspace->gatherMiddleware());
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());
        $this->assertSame('tenant.view,ai.use', $workspace->defaults['workspace_permissions']);
        $this->assertSame('AIMW-AI-82F795EE67', $workspace->defaults['canonical_operation_id']);

        $read = Route::getRoutes()->match(Request::create('/api/tenants/alpha/ai-center', 'GET'));
        $this->assertSame(AiCenterReadController::class, ltrim($read->getActionName(), '\\'));
        $this->assertContains('auth', $read->gatherMiddleware());
        $this->assertContains('tenant.context', $read->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $read->methods());
    }

    public function test_route_reads_only_real_active_tenant_prompt_and_current_user_usage_state(): void
    {
        $this->withoutVite();

        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        $membership = $this->membership($user, $alpha, ['tenant.view', 'ai.use']);
        $otherMembership = $this->membership($other, $alpha, ['tenant.view', 'ai.use']);
        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);

        $alphaPrompt = $this->prompt($membership, 'content.rewrite', 'Content Rewrite', true);
        $this->prompt($membership, 'disabled.prompt', 'Disabled Prompt', false);
        $this->prompt($betaMembership, 'beta.secret', 'Beta Secret Prompt', true);
        $this->usage($membership, $user);
        $this->usage($otherMembership, $other);

        $beforePrompts = AiPromptTemplate::query()->withoutGlobalScopes()->count();
        $beforeUsage = AiUsageRecord::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)->withSession([])->get('/tenants/alpha/ai-center')
            ->assertOk();

        $response = $this->actingAs($user)->getJson('/api/tenants/alpha/ai-center');
        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alphaPrompt->id)
            ->assertJsonPath('data.0.key', 'content.rewrite')
            ->assertJsonPath('data.0.title', 'Content Rewrite')
            ->assertJsonPath('meta.available_prompts', 1)
            ->assertJsonPath('meta.recent_usage_count', 1);

        $this->assertStringNotContainsString('Beta Secret Prompt', $response->getContent());
        $this->assertStringNotContainsString('Disabled Prompt', $response->getContent());
        $this->assertSame($beforePrompts, AiPromptTemplate::query()->withoutGlobalScopes()->count());
        $this->assertSame($beforeUsage, AiUsageRecord::query()->withoutGlobalScopes()->count());
    }

    public function test_ai_center_route_fails_closed_for_guest_permission_and_foreign_tenant_access(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $allowed = User::factory()->create();
        $limited = User::factory()->create();
        $this->membership($allowed, $alpha, ['tenant.view', 'ai.use']);
        $this->membership($limited, $alpha, ['tenant.view']);
        $betaUser = User::factory()->create();
        $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);

        $this->get('/tenants/alpha/ai-center')->assertRedirect('/login');
        $this->getJson('/api/tenants/alpha/ai-center')->assertUnauthorized();

        $this->actingAs($limited)->get('/tenants/alpha/ai-center')->assertForbidden();
        $this->actingAs($limited)->getJson('/api/tenants/alpha/ai-center')->assertForbidden();

        $this->actingAs($allowed)->get('/tenants/beta/ai-center')->assertNotFound();
        $this->actingAs($allowed)->getJson('/api/tenants/beta/ai-center')->assertNotFound();
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'ai-center-route-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function prompt(TenantMembership $membership, string $key, string $title, bool $enabled): AiPromptTemplate
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $prompt = AiPromptTemplate::query()->create([
            'stable_key' => $key,
            'domain' => 'content',
            'title' => $title,
            'user_template' => 'Improve {{content}}',
            'variables' => ['content'],
            'enabled' => $enabled,
            'current_version' => 1,
            'updated_by_user_id' => $membership->user_id,
        ]);
        $context->forget();

        return $prompt;
    }

    private function usage(TenantMembership $membership, User $user): AiUsageRecord
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $usage = AiUsageRecord::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'test-provider',
            'model_key' => 'test-model',
            'workflow' => 'ai-center-route-test',
            'status' => 'succeeded',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
        $context->forget();

        return $usage;
    }
}
