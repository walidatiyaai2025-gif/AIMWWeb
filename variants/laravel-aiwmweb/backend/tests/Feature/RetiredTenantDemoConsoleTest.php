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

final class RetiredTenantDemoConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_tenant_member_can_no_longer_access_the_legacy_mutation_demo(): void
    {
        $tenant = Tenant::query()->create(['name' => 'WordPress', 'slug' => 'wordpress']);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'console-decommission-member']);
        $permission = Permission::query()->firstOrCreate(['name' => 'tenant.view']);
        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        $response = $this->actingAs($user)->get('/tenants/wordpress/console');

        $response->assertNotFound()
            ->assertDontSee('Demo Journey')
            ->assertDontSee('Generate pairing')
            ->assertDontSee('Run audit')
            ->assertDontSee('X-CSRF-TOKEN');

        $this->assertFalse(view()->exists('console'));
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('seo_audits', 0);
        $this->assertDatabaseCount('executions', 0);
    }

    public function test_retired_console_preserves_guest_auth_guard_and_real_api_route_registry(): void
    {
        $response = $this->get('/tenants/wordpress/console');

        $response->assertRedirect();
        $this->assertStringContainsString('/login', (string) $response->headers->get('Location'));

        $api = Route::getRoutes()->match(Request::create('/api/tenants/wordpress/sites', 'GET'));
        $this->assertSame('api/tenants/{tenant}/sites', $api->uri());
    }
}
