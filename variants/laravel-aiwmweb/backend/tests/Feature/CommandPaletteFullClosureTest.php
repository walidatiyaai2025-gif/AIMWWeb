<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommandPaletteFullClosureTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-2C653A870A';

    public function test_exact_canonical_operation_is_the_pending_open_command_palette_control(): void
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
        $this->assertSame('@(L.IsArabic ? [OpenCommandPalette]', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertFalse((bool) $operation['connector_required']);
    }

    public function test_frontend_context_route_is_real_authenticated_and_tenant_scoped(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/context', 'GET'));

        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertStringContainsString('Closure', $route->getActionName());
    }

    public function test_context_returns_authoritative_active_tenant_permissions_and_urls(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $this->membership($user, 'beta', ['tenant.view', 'content.view']);

        $response = $this->actingAs($user)->getJson('/tenants/alpha/context');

        $response->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('api.sites', '/api/tenants/alpha/sites')
            ->assertJsonFragment(['slug' => 'alpha', 'name' => 'Alpha'])
            ->assertJsonFragment(['slug' => 'beta', 'name' => 'Beta']);

        $permissions = $response->json('permissions');
        $this->assertContains('tenant.view', $permissions);
        $this->assertContains('sites.view', $permissions);
        $this->assertNotContains('content.view', $permissions);
    }

    public function test_guest_missing_permission_and_cross_tenant_context_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);

        $limitedUser = User::factory()->create();
        $this->membership($limitedUser, 'limited', ['sites.view']);

        $this->get('/tenants/alpha/context')->assertRedirect('/login');
        $this->actingAs($limitedUser)->getJson('/tenants/limited/context')->assertForbidden();
        $this->actingAs($alphaUser)->getJson('/tenants/beta/context')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "command-palette-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
