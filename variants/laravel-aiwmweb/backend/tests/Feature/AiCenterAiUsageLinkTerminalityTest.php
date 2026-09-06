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

final class AiCenterAiUsageLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-331ED9D5EE';

    public function test_exact_canonical_row_is_generator_backed_adapted_ai_center_to_ai_usage_navigation(): void
    {
        $payload = $this->reconciliation();
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('/ai-center', $row['route_screen']);
        $this->assertStringContainsString('/module/ai-usage', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AICenter.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('focused_closure_contract', $row['reconciliation']['evidence_mode']);
        $this->assertContains(self::OPERATION_ID, $payload['validation']['focused_closure_contract_terminals']);
        $this->assertTrue((bool) ($payload['validation']['passed'] ?? false));
    }

    public function test_frontend_control_is_wired_only_through_the_ai_center_host_and_uses_authoritative_tenant_context(): void
    {
        $control = (string) file_get_contents(resource_path('js/ai-center-ai-usage-link-control.tsx'));
        $host = (string) file_get_contents(resource_path('js/ai-center-approval-status-control.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/ai-usage')", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.use')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.viewUsage')", $control);
        $this->assertStringContainsString('AiCenterAiUsageLinkControl', $host);
        $this->assertStringContainsString('<AiCenterAiUsageLinkControl context={context} />', $host);
    }

    public function test_authorized_user_can_open_source_and_destination_without_mutating_usage_or_approval_state(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'ai.use', 'ai.viewUsage']);
        $this->withoutVite();

        $beforeUsage = AiUsageRecord::query()->withoutGlobalScopes()->count();
        $beforeApprovals = Approval::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/ai-center')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonFragment(['tenant.view'])
            ->assertJsonFragment(['ai.use'])
            ->assertJsonFragment(['ai.viewUsage']);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/ai-usage')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($beforeUsage, AiUsageRecord::query()->withoutGlobalScopes()->count());
        $this->assertSame($beforeApprovals, Approval::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_permission_and_foreign_tenant_paths_fail_closed(): void
    {
        Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->get('/tenants/alpha/ai-center')->assertRedirect('/login');
        $this->get('/tenants/alpha/module/ai-usage')->assertRedirect('/login');

        $sourceLimited = User::factory()->create();
        $this->membership($sourceLimited, 'source-limited', ['tenant.view', 'ai.viewUsage']);
        $this->withoutVite();
        $this->actingAs($sourceLimited)->get('/tenants/source-limited/ai-center')->assertForbidden();

        $destinationLimited = User::factory()->create();
        $this->membership($destinationLimited, 'destination-limited', ['tenant.view', 'ai.use']);
        $this->actingAs($destinationLimited)->get('/tenants/destination-limited/module/ai-usage')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'member-alpha', ['tenant.view', 'ai.use', 'ai.viewUsage']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($alpha)->get('/tenants/foreign/ai-center')->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/foreign/module/ai-usage')->assertNotFound();
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
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-center-usage-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
