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

class QuickActionsToggleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-PLAT-4C37AC806E';

    public function test_exact_canonical_operation_is_the_pending_quick_actions_toggle(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:QuickActions', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? [Toggle]', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/QuickActions.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertFalse((bool) $operation['tenant_owned']);
    }

    public function test_authenticated_tenant_context_is_required_before_the_toggle_can_receive_authoritative_routes(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view'], 'Owner');

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->json();

        $this->assertSame('alpha', data_get($context, 'tenant.slug'));
        $this->assertContains('tenant.view', $context['permissions'] ?? []);
        $this->assertContains('content.view', $context['permissions'] ?? []);
    }

    public function test_guest_missing_permission_and_cross_tenant_context_fail_closed(): void
    {
        $this->get('/tenants/alpha/context')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', [], 'Limited');
        $this->actingAs($limited)->getJson('/tenants/limited/context')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view'], 'Alpha Role');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view'], 'Beta Role');

        $this->actingAs($alpha)->getJson('/tenants/beta/context')->assertNotFound();
    }

    public function test_runtime_binding_uses_exact_marker_capability_filtering_and_context_derived_tenant_urls(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/quick-actions-toggle-control.tsx'));

        $this->assertStringContainsString("import { QuickActionsToggleControl } from './quick-actions-toggle-control';", $runtime);
        $this->assertStringContainsString('<QuickActionsToggleControl context={context} />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('resolveCapability(context, route).state === \'enabled\'', $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, route.path)', $control);
        $this->assertStringContainsString("document.querySelector<HTMLElement>('.topbar-actions')", $control);
        $this->assertStringContainsString("'/content-planner'", $control);
        $this->assertStringContainsString("'/module/posts'", $control);
        $this->assertStringNotContainsString('tenant=', $control);
        $this->assertSame(1, substr_count($control, self::OPERATION_ID));
        $this->assertStringContainsString(
            "export const QUICK_ACTIONS_CLOSE_OPERATION = 'AIMW-PLAT-17BC7DA9E5';",
            $control,
        );
        $this->assertSame(1, substr_count($control, 'AIMW-PLAT-17BC7DA9E5'));
        $this->assertSame(1, substr_count($control, 'data-canonical-operation={QUICK_ACTIONS_TOGGLE_OPERATION}'));
        $this->assertSame(1, substr_count($control, 'data-canonical-operation={QUICK_ACTIONS_CLOSE_OPERATION}'));
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
