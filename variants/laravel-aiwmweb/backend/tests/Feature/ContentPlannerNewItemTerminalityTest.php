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

class ContentPlannerNewItemTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3ABDE4E48F';

    public function test_source_new_item_contract_and_laravel_binding_are_exact(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor'));
        $control = (string) file_get_contents(resource_path('js/content-planner-new-item-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('@onclick="NewItem"', $source);
        $this->assertStringContainsString('private void NewItem()', $source);
        $this->assertStringContainsString('_editingId = null;', $source);
        $this->assertStringContainsString('_selectedSiteId = string.Empty;', $source);
        $this->assertStringContainsString('_title = _idea = _scheduledLocal = string.Empty;', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('editingId: null', $control);
        $this->assertStringContainsString("selectedSiteId: ''", $control);
        $this->assertStringContainsString("title: ''", $control);
        $this->assertStringContainsString("idea: ''", $control);
        $this->assertStringContainsString("scheduledLocal: ''", $control);
        $this->assertStringContainsString('ContentPlannerNewItemControl context={context}', $app);
    }

    public function test_new_item_control_is_local_only_and_adds_no_backend_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-planner-new-item-control.tsx'));

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringNotContainsString('method:', $control);
    }

    public function test_content_planner_host_route_preserves_permission_and_foreign_tenant_isolation(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($user)
            ->get('/tenants/alpha/content-planner')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/foreign/content-planner')
            ->assertNotFound();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/content-planner')
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "planner-new-item-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }
}
