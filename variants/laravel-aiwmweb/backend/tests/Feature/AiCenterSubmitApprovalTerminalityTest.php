<?php

namespace Tests\Feature;

use App\Execution\ExecutionCreator;
use App\Http\Controllers\AiCenterApprovalSubmissionController;
use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiCenterSubmitApprovalTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-93EBFDE5A1';

    public function test_exact_canonical_operation_is_ai_center_submit_for_approval(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertContains($operation['migration_state'], ['PENDING', 'ADAPTED']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/ai-center', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AICenter.razor', $operation['current_source']);
        $this->assertStringContainsString('SubmitForApprovalClickedAsync', $operation['visible_control']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_submit_route_is_explicit_guarded_and_carries_exact_operation_provenance(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/ai-center/approvals', 'POST'));

        $this->assertSame(AiCenterApprovalSubmissionController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
    }

    public function test_authorized_user_creates_exact_structured_pending_approval_and_queue_can_read_it(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create(['name' => 'Alpha Operator']);
        $membership = $this->membership($user, $tenant, ['tenant.view', 'ai.use', 'approvals.view']);
        $site = $this->site($membership, 'Alpha Site');

        $payload = $this->payload([
            'site_id' => $site->id,
            'operation_type' => 'AI.ContentRewrite',
            'title' => 'Rewrite homepage',
            'risk_level' => 'Critical',
        ]);

        $response = $this->actingAs($user)->postJson('/api/tenants/alpha/ai-center/approvals', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.site_name', 'Alpha Site')
            ->assertJsonPath('data.operation_type', 'AI.ContentRewrite')
            ->assertJsonPath('data.title', 'Rewrite homepage')
            ->assertJsonPath('data.risk_level', 'Critical');

        $approval = Approval::query()->withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        $this->assertNull($approval->suggestion_id);
        $this->assertSame($tenant->id, $approval->tenant_id);
        $this->assertSame($user->id, $approval->actor_user_id);
        $this->assertSame(self::OPERATION_ID, $approval->source_operation_id);
        $this->assertSame('Alpha Operator', $approval->actor_label);
        $this->assertSame('Original article', $approval->before_state['Content']);
        $this->assertSame('content.rewrite', $approval->before_state['PromptKey']);
        $this->assertSame('gpt-test', $approval->before_state['Model']);
        $this->assertSame(['title', 'content'], $approval->before_state['AffectedFields']);
        $this->assertSame('Improved article', $approval->proposed_state['Content']);
        $this->assertSame('Tightened the introduction and headings.', $approval->proposed_state['Explanation']);
        $this->assertSame(0.91, $approval->proposed_state['Confidence']);

        $this->actingAs($user)->getJson('/api/tenants/alpha/approvals')
            ->assertOk()
            ->assertJsonPath('data.0.id', $approval->id)
            ->assertJsonPath('data.0.source_operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.0.title', 'Rewrite homepage')
            ->assertJsonPath('data.0.suggestion_id', null);
    }

    public function test_request_key_is_idempotent_within_tenant_and_defaults_match_source(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $this->membership($user, $tenant, ['tenant.view', 'ai.use']);
        $payload = $this->payload([
            'site_id' => null,
            'operation_type' => '   ',
            'title' => '   ',
        ]);

        $first = $this->actingAs($user)->postJson('/api/tenants/alpha/ai-center/approvals', $payload);
        $second = $this->actingAs($user)->postJson('/api/tenants/alpha/ai-center/approvals', $payload);

        $first->assertCreated();
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Approval::query()->withoutGlobalScopes()->where('request_key', $payload['request_key'])->count());

        $approval = Approval::query()->withoutGlobalScopes()->findOrFail((int) $first->json('data.id'));
        $this->assertNull($approval->site_id);
        $this->assertNull($approval->site_name);
        $this->assertSame('AI.ContentUpdate', $approval->operation_type);
        $this->assertSame('AI content proposal', $approval->title);
        $this->assertSame('High', $approval->risk_level);
        $this->assertSame('PENDING', $approval->status);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $allowed = User::factory()->create();
        $limited = User::factory()->create();
        $betaUser = User::factory()->create();
        $this->membership($allowed, $alpha, ['tenant.view', 'ai.use']);
        $this->membership($limited, $alpha, ['tenant.view']);
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->postJson('/api/tenants/alpha/ai-center/approvals', $this->payload())
            ->assertUnauthorized();
        $this->actingAs($limited)->postJson('/api/tenants/alpha/ai-center/approvals', $this->payload([
            'request_key' => '11111111-1111-4111-8111-111111111112',
        ]))->assertForbidden();
        $this->actingAs($allowed)->postJson('/api/tenants/beta/ai-center/approvals', $this->payload([
            'request_key' => '11111111-1111-4111-8111-111111111113',
        ]))->assertNotFound();
        $this->actingAs($allowed)->postJson('/api/tenants/alpha/ai-center/approvals', $this->payload([
            'request_key' => '11111111-1111-4111-8111-111111111114',
            'site_id' => $betaSite->id,
        ]))->assertNotFound();

        $this->assertSame(0, Approval::query()->withoutGlobalScopes()->count());
    }

    public function test_generic_ai_approval_cannot_fall_through_to_seo_specific_executor(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'ai.use']);
        $created = $this->actingAs($user)->postJson('/api/tenants/alpha/ai-center/approvals', $this->payload());
        $created->assertCreated();

        $approval = Approval::query()->withoutGlobalScopes()->findOrFail((int) $created->json('data.id'));
        $approval->update(['status' => 'APPROVED', 'decided_at' => now()]);

        $context = app(TenantContext::class);
        $context->activate($tenant, $membership);
        try {
            app(ExecutionCreator::class)->create($approval, $user->id);
            $this->fail('Generic AI approval unexpectedly entered the SEO suggestion executor.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('This approval requires a domain-specific executor.', $exception->getMessage());
        } finally {
            $context->forget();
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'request_key' => '11111111-1111-4111-8111-111111111111',
            'site_id' => null,
            'operation_type' => 'AI.ContentUpdate',
            'title' => 'AI content proposal',
            'risk_level' => 'High',
            'before_content' => 'Original article',
            'after_content' => 'Improved article',
            'prompt_key' => 'content.rewrite',
            'model' => 'gpt-test',
            'explanation' => 'Tightened the introduction and headings.',
            'confidence' => 0.91,
            'affected_fields' => ['title', 'content'],
        ], $overrides);
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'ai-submit-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
        ]);
        $context->forget();

        return $site;
    }
}
