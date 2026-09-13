<?php

namespace Tests\Feature;

use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Quota\DatabaseAiQuotaGateway;
use App\Http\Controllers\AiCenterGenerateController;
use App\Models\AiGenerationRecord;
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
use Tests\TestCase;

class AiCenterGenerateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-DDB072FE15';

    public function test_exact_canonical_operation_is_ai_center_generate_suggestion(): void
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
        $this->assertStringContainsString('GenerateClickedAsync', $operation['visible_control']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_generate_route_is_guarded_and_carries_exact_operation_provenance(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/ai-center/generate', 'POST'));

        $this->assertSame(AiCenterGenerateController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
    }

    public function test_authorized_user_generates_reviewable_structured_suggestion_without_external_side_effect(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'ai.use']);
        $site = $this->site($membership, 'Alpha Site');
        $fake = new RecordingAiGenerator;
        $this->app->instance(AiGenerator::class, $fake);

        $response = $this->actingAs($user)->postJson('/api/tenants/alpha/ai-center/generate', [
            'content' => 'Original article body',
            'model' => 'model-test',
            'temperature' => 0.4,
            'max_output_tokens' => 900,
            'site_id' => $site->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.before', 'Original article body')
            ->assertJsonPath('data.after', 'Improved article body')
            ->assertJsonPath('data.explanation', 'Improved structure without publishing anything.')
            ->assertJsonPath('data.confidence', 0.93)
            ->assertJsonPath('data.affected_fields.0', 'content')
            ->assertJsonPath('data.provider', 'provider-test')
            ->assertJsonPath('data.model', 'model-test')
            ->assertJsonPath('data.site_id', $site->id);

        $this->assertSame('ai.suggestion', $fake->request['workflow']);
        $this->assertSame('Original article body', $fake->request['user_prompt']);
        $this->assertSame('model-test', $fake->request['model']);
        $this->assertSame(0.4, $fake->request['temperature']);
        $this->assertSame(900, $fake->request['max_output_tokens']);
        $this->assertSame($site->id, $fake->request['site_id']);
        $this->assertSame('object', $fake->request['output_schema']['type']);
        $this->assertStringNotContainsString('secret-test-value', $response->getContent());
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed_without_generation(): void
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
        $fake = new RecordingAiGenerator;
        $this->app->instance(AiGenerator::class, $fake);

        $payload = ['content' => 'Do not run for unauthorized callers.'];
        $this->postJson('/api/tenants/alpha/ai-center/generate', $payload)->assertUnauthorized();
        $this->actingAs($limited)->postJson('/api/tenants/alpha/ai-center/generate', $payload)->assertForbidden();
        $this->actingAs($allowed)->postJson('/api/tenants/beta/ai-center/generate', $payload)->assertNotFound();
        $this->actingAs($allowed)->postJson('/api/tenants/alpha/ai-center/generate', [
            ...$payload,
            'site_id' => $betaSite->id,
        ])->assertNotFound();

        $this->assertSame(0, $fake->calls);
    }

    public function test_database_quota_gateway_is_operational_and_fails_closed_on_context_mismatch(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'ai.use']);
        $context = app(TenantContext::class);
        $context->activate($tenant, $membership);
        config(['ai.daily_generation_limit' => 1]);

        try {
            $gateway = app(DatabaseAiQuotaGateway::class);
            $this->assertTrue($gateway->check($tenant->id, $user->id, 'ai.suggestion')['allowed']);

            AiGenerationRecord::query()->create([
                'user_id' => $user->id,
                'workflow' => 'ai.suggestion',
                'request_hash' => str_repeat('a', 64),
                'correlation_id' => '11111111-1111-4111-8111-111111111111',
                'status' => 'succeeded',
                'retry_count' => 0,
                'started_at' => now(),
                'completed_at' => now(),
                'created_at' => now(),
            ]);

            $exceeded = $gateway->check($tenant->id, $user->id, 'ai.suggestion');
            $this->assertFalse($exceeded['allowed']);
            $this->assertSame('quota_exceeded', $exceeded['code']);
            $this->assertSame(1, $exceeded['limit']);
            $this->assertSame(1, $exceeded['current']);

            $mismatch = $gateway->check($tenant->id + 1, $user->id, 'ai.suggestion');
            $this->assertFalse($mismatch['allowed']);
            $this->assertSame('quota_backend_unavailable', $mismatch['code']);
        } finally {
            $context->forget();
        }
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'ai-generate-'.$tenant->slug.'-'.$user->id]);
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

final class RecordingAiGenerator implements AiGenerator
{
    public int $calls = 0;

    public array $request = [];

    public function generate(array $request): array
    {
        $this->calls++;
        $this->request = $request;

        return [
            'correlation_id' => '22222222-2222-4222-8222-222222222222',
            'provider' => 'provider-test',
            'model' => (string) ($request['model'] ?? 'model-default'),
            'content' => '{"after":"Improved article body"}',
            'structured' => [
                'after' => 'Improved article body',
                'explanation' => 'Improved structure without publishing anything.',
                'confidence' => 0.93,
                'affected_fields' => ['content'],
            ],
        ];
    }
}
