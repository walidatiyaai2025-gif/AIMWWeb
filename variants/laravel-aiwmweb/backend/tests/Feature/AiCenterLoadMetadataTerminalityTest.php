<?php

namespace Tests\Feature;

use App\Http\Controllers\AiCenterReadController;
use App\Models\AiPromptTemplate;
use App\Models\AiUsageRecord;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCenterLoadMetadataTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_load_metadata_control_is_bound_to_the_real_read_only_contract(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', AiCenterReadController::METADATA_REFRESH_OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('/ai-center', $operation['route_screen']);
        $this->assertStringContainsString('LoadMetadataClickedAsync', $operation['visible_control']);
        $this->assertStringEndsWith('AICenter.razor', $operation['current_source']);
        $this->assertFalse($operation['mutation']);
        $this->assertTrue($operation['tenant_owned']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/ai-center', 'GET'));
        $this->assertSame(AiCenterReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $frontend = file_get_contents(resource_path('js/pages.tsx'));
        $this->assertIsString($frontend);
        $this->assertStringContainsString(AiCenterReadController::METADATA_REFRESH_OPERATION_ID, $frontend);
        $this->assertStringContainsString('Refresh data', $frontend);
        $this->assertStringContainsString('Authoritative state', $frontend);
    }

    public function test_refresh_rebuilds_authoritative_current_user_metadata_without_cross_tenant_or_cross_user_leakage(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $betaUser = User::factory()->create();
        $ownerMembership = $this->membership($owner, $alpha, ['tenant.view', 'ai.use']);
        $otherMembership = $this->membership($other, $alpha, ['tenant.view', 'ai.use']);
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);

        $this->prompt($ownerMembership, 'Zulu.prompt', 'Zulu Prompt', true);
        $this->prompt($ownerMembership, 'alpha.prompt', 'Alpha Prompt', true);
        $this->prompt($ownerMembership, 'disabled.prompt', 'Disabled Prompt', false);
        $this->prompt($betaMembership, 'beta.secret', 'Beta Secret Prompt', true);

        for ($index = 0; $index < 101; $index++) {
            $this->usage($ownerMembership, $owner, $index);
        }
        $this->usage($otherMembership, $other, 500);
        $this->usage($betaMembership, $betaUser, 600);

        $this->site($ownerMembership, 'Zulu Site');
        $this->site($ownerMembership, 'Alpha Site');
        $this->site($betaMembership, 'Beta Secret Site');

        $this->approval($ownerMembership, 'PENDING');
        $latestOwnedApproval = $this->approval($ownerMembership, 'APPROVED');
        $foreignUserApproval = $this->approval($otherMembership, 'REJECTED');
        $this->approval($betaMembership, 'REJECTED');

        $before = [
            'prompts' => AiPromptTemplate::query()->withoutGlobalScopes()->count(),
            'usage' => AiUsageRecord::query()->withoutGlobalScopes()->count(),
            'sites' => Site::query()->withoutGlobalScopes()->count(),
            'approvals' => Approval::query()->withoutGlobalScopes()->count(),
        ];

        $response = $this->actingAs($owner)->getJson('/api/tenants/alpha/ai-center');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.key', 'alpha.prompt')
            ->assertJsonPath('data.1.key', 'Zulu.prompt')
            ->assertJsonPath('meta.operation_id', AiCenterReadController::METADATA_REFRESH_OPERATION_ID)
            ->assertJsonPath('meta.locale', app()->getLocale())
            ->assertJsonPath('meta.available_prompts', 2)
            ->assertJsonPath('meta.recent_usage_count', 100)
            ->assertJsonPath('meta.sites.0.name', 'Alpha Site')
            ->assertJsonPath('meta.sites.1.name', 'Zulu Site')
            ->assertJsonPath('meta.approval.id', $latestOwnedApproval->id)
            ->assertJsonPath('meta.approval.status', 'APPROVED');

        $body = $response->getContent();
        $this->assertStringNotContainsString('Disabled Prompt', $body);
        $this->assertStringNotContainsString('Beta Secret Prompt', $body);
        $this->assertStringNotContainsString('Beta Secret Site', $body);
        $this->assertNotSame($foreignUserApproval->id, $response->json('meta.approval.id'));
        $this->assertArrayNotHasKey('url', $response->json('meta.sites.0'));

        $after = [
            'prompts' => AiPromptTemplate::query()->withoutGlobalScopes()->count(),
            'usage' => AiUsageRecord::query()->withoutGlobalScopes()->count(),
            'sites' => Site::query()->withoutGlobalScopes()->count(),
            'approvals' => Approval::query()->withoutGlobalScopes()->count(),
        ];
        $this->assertSame($before, $after);
    }

    public function test_refresh_fails_closed_for_guest_missing_permission_and_foreign_tenant_access(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $allowed = User::factory()->create();
        $limited = User::factory()->create();
        $this->membership($allowed, $alpha, ['tenant.view', 'ai.use']);
        $this->membership($limited, $alpha, ['tenant.view']);
        $betaUser = User::factory()->create();
        $this->membership($betaUser, $beta, ['tenant.view', 'ai.use']);

        $this->getJson('/api/tenants/alpha/ai-center')->assertUnauthorized();
        $this->actingAs($limited)->getJson('/api/tenants/alpha/ai-center')->assertForbidden();
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
        $role = Role::query()->create(['name' => 'ai-center-load-metadata-'.$tenant->slug.'-'.$user->id]);
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

    private function usage(TenantMembership $membership, User $user, int $sequence): AiUsageRecord
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $usage = AiUsageRecord::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'test-provider',
            'model_key' => 'test-model',
            'workflow' => 'ai-center-load-metadata-test',
            'status' => 'succeeded',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now()->addSeconds($sequence),
        ]);
        $context->forget();

        return $usage;
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'-'.$membership->tenant->slug.'.test',
        ]);
        $context->forget();

        return $site;
    }

    private function approval(TenantMembership $membership, string $status): Approval
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $approval = Approval::query()->create([
            'suggestion_id' => null,
            'actor_user_id' => $membership->user_id,
            'status' => $status,
            'source_operation_id' => 'AIMW-AI-953A6C0D98-TEST',
            'before_state' => ['content' => 'before'],
            'proposed_state' => ['content' => 'after'],
            'decided_at' => $status === 'PENDING' ? null : now(),
        ]);
        $context->forget();

        return $approval;
    }
}
