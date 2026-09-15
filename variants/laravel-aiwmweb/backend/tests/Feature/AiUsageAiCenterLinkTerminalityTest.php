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

final class AiUsageAiCenterLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-411CFF23F3';

    public function test_exact_canonical_row_is_the_adapted_ai_usage_to_ai_center_navigation(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('/module/ai-usage', $row['route_screen']);
        $this->assertStringContainsString('/ai-center', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIUsage.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
    }

    public function test_frontend_control_is_wired_only_on_the_ai_usage_workspace(): void
    {
        $control = (string) file_get_contents(resource_path('js/ai-usage-ai-center-link-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/ai-center')", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.viewUsage')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.use')", $control);
        $this->assertStringContainsString('AiUsageAiCenterLinkControl', $app);
        $this->assertStringContainsString("route.key === 'ai-usage'", $app);
        $this->assertStringNotContainsString('AIMW-AI-331ED9D5EE', $control);
        $this->assertStringNotContainsString('AIMW-AI-82F795EE67', $control);
    }

    public function test_authorized_user_can_follow_the_real_tenant_qualified_navigation_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'ai.viewUsage', 'ai.use']);
        $this->withoutVite();

        $beforeUsage = AiUsageRecord::query()->withoutGlobalScopes()->count();
        $beforeApprovals = Approval::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/module/ai-usage')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonFragment(['ai.viewUsage'])
            ->assertJsonFragment(['ai.use']);

        $this->actingAs($user)
            ->get('/tenants/alpha/ai-center')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($beforeUsage, AiUsageRecord::query()->withoutGlobalScopes()->count());
        $this->assertSame($beforeApprovals, Approval::query()->withoutGlobalScopes()->count());
    }

    public function test_missing_source_permission_and_foreign_tenant_navigation_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view', 'ai.use']);
        $this->withoutVite();
        $this->actingAs($limited)->get('/tenants/limited/module/ai-usage')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'ai.viewUsage', 'ai.use']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($alpha)->get('/tenants/foreign/module/ai-usage')->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/foreign/ai-center')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-usage-center-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
