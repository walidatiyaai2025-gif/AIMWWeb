<?php

namespace Tests\Feature;

use App\Models\AiUsageRecord;
use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCenterCopyOutputTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-B711182657';

    public function test_exact_canonical_operation_is_ai_center_copy_output(): void
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
        $this->assertStringContainsString('CopyOutputClickedAsync', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_real_ai_center_host_wires_exact_conditional_clipboard_copy_without_server_mutation(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/ai-center-approval-status-control.tsx'));

        $this->assertStringContainsString("route.key === 'ai-center'", $app);
        $this->assertStringContainsString('<AiCenterApprovalStatusControl context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('ai.use')", $control);
        $this->assertStringContainsString("output.trim() !== ''", $control);
        $this->assertStringContainsString('navigator.clipboard', $control);
        $this->assertStringContainsString('await clipboard.writeText(output)', $control);
        $this->assertStringContainsString("locale === 'ar' ? 'تم نسخ الاقتراح.' : 'Suggestion copied.'", $control);

        preg_match('/const copyOutput = async \(\) => \{(?<body>.*?)\n    \};/s', $control, $match);
        $body = $match['body'] ?? '';
        $this->assertNotSame('', $body);
        $this->assertStringContainsString("output.trim() === ''", $body);
        $this->assertStringContainsString('await clipboard.writeText(output)', $body);
        $this->assertStringContainsString("setCopyState('success')", $body);
        $this->assertStringContainsString("setCopyState('error')", $body);
        $this->assertStringNotContainsString('apiRequest', $body);
        $this->assertStringNotContainsString('fetch(', $body);
        $this->assertStringNotContainsString('setApproval(', $body);
        $this->assertStringNotContainsString('setHistory(', $body);
    }

    public function test_authorized_ai_center_render_and_context_expose_no_copy_output_server_action(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'ai.use']);
        $this->withoutVite();

        $beforeApprovals = Approval::query()->withoutGlobalScopes()->count();
        $beforeUsage = AiUsageRecord::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/ai-center')
            ->assertOk()
            ->assertSee('id="app"', false);

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha');

        $this->assertContains('ai.use', $context->json('permissions'));
        $this->assertArrayNotHasKey('ai.copy-output', $context->json('actions') ?? []);
        $this->assertSame($beforeApprovals, Approval::query()->withoutGlobalScopes()->count());
        $this->assertSame($beforeUsage, AiUsageRecord::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_missing_ai_authority_and_foreign_tenant_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/ai-center')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $limitedContext = $this->actingAs($limited)
            ->getJson('/tenants/limited/context')
            ->assertOk();
        $this->assertNotContains('ai.use', $limitedContext->json('permissions'));
        $this->assertArrayNotHasKey('ai.copy-output', $limitedContext->json('actions') ?? []);

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'ai.use']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'ai.use']);

        $this->actingAs($alpha)->get('/tenants/beta/ai-center')->assertNotFound();
        $this->actingAs($alpha)->getJson('/tenants/beta/context')->assertNotFound();
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
        $role = Role::query()->create(['name' => "ai-center-copy-output-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
