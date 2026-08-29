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

class CommandPaletteCloseFullClosureTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-D3A8A100B4';

    public function test_exact_canonical_operation_is_the_pending_close_command_palette_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:MainLayout', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/MainLayout.razor', $operation['current_source']);
        $this->assertSame('@(L.IsArabic ? [CloseCommandPalette]', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['connector_required']);
    }

    public function test_runtime_is_bound_to_the_exact_close_control_and_source_equivalent_close_paths(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));

        $this->assertStringContainsString('data-canonical-operation="AIMW-AI-D3A8A100B4"', $components);
        $this->assertStringContainsString("event.key !== 'Escape'", $components);
        $this->assertStringContainsString("event.preventDefault();", $components);
        $this->assertStringContainsString("trigger?.focus()", $components);
        $this->assertStringContainsString("event.target === event.currentTarget && onClose()", $components);
        $this->assertStringContainsString("'Close search'", $components);
    }

    public function test_authenticated_tenant_shell_remains_fail_closed_for_guest_permission_and_foreign_tenant(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);

        $limitedUser = User::factory()->create();
        $this->membership($limitedUser, 'limited', ['sites.view']);

        $this->getJson('/tenants/alpha/context')->assertUnauthorized();
        $this->actingAs($limitedUser)->getJson('/tenants/limited/context')->assertForbidden();
        $this->actingAs($alphaUser)->getJson('/tenants/beta/context')->assertNotFound();

        $response = $this->actingAs($alphaUser)->getJson('/tenants/alpha/context');
        $response->assertOk()->assertJsonPath('tenant.slug', 'alpha');
        $this->assertContains('tenant.view', $response->json('permissions'));
        $this->assertContains('sites.view', $response->json('permissions'));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "command-palette-close-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
