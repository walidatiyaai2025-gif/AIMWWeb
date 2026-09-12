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

class AutomationPhaseVisibleControlsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_IDS = [
        'AIMW-AUTO-3C8B141746',
        'AIMW-AUTO-647F50CC73',
        'AIMW-AUTO-E4269EADC3',
        'AIMW-AUTO-7C13E2AA0B',
        'AIMW-AUTO-6EB9542C7A',
        'AIMW-AUTO-B2CDFF403F',
        'AIMW-AUTO-C11296372B',
        'AIMW-AUTO-66466C8C7F',
        'AIMW-AUTO-C4A8DCCFEF',
    ];

    public function test_production_frontend_contains_all_exact_automation_control_bindings(): void
    {
        $controls = (string) file_get_contents(resource_path('js/automation-phase-controls.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $dashboard = (string) file_get_contents(resource_path('js/dashboard-execution-link-control.tsx'));
        $source = implode("\n", [$controls, $pages, $app, $dashboard]);

        foreach (self::OPERATION_IDS as $operationId) {
            $this->assertStringContainsString($operationId, $source);
        }

        $this->assertStringContainsString('AUTOMATION_PHASE_REFRESH_OPERATIONS[route.key]', $pages);
        $this->assertStringContainsString('AUTOMATION_PHASE_ACTION_OPERATIONS[actionKey]', $pages);
        $this->assertStringContainsString('setDialog(null)', $pages);
        $this->assertStringContainsString('AutomationPhaseNavigationControls context={context}', $app);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/execution')", $controls);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/module/sync')", $controls);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites/connect')", $controls);
        $this->assertStringContainsString('data-home-canonical-operation={HOME_EXECUTION_LINK_OPERATION_ID}', $dashboard);
    }

    public function test_automation_workspaces_are_tenant_scoped_and_foreign_tenant_returns_not_found(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'tenant-a', [
            'automation.view', 'automation.manage', 'execution.view', 'operations.manage',
            'sync.view', 'sites.view', 'sites.manage', 'reports.view', 'reports.export', 'content.view',
        ]);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'tenant-b', [
            'automation.view', 'automation.manage', 'execution.view', 'operations.manage',
            'sync.view', 'sites.view', 'sites.manage', 'reports.view', 'reports.export', 'content.view',
        ]);

        $this->actingAs($alphaUser)
            ->get('/tenants/tenant-b/module/schedules')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/tenant-a/module/schedules')
            ->assertOk();
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "automation-visible-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
