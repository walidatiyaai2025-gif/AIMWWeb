<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupMutationController;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SetupMutationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_setup_post_is_anonymous_web_mutation_with_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('canonical.api.setup.submit');

        $this->assertNotNull($route);
        $this->assertSame(SetupMutationController::class, $route->getActionName());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_fresh_setup_creates_one_hashed_owner_and_real_rbac_then_redirects(): void
    {
        $password = 'correct-horse-battery-staple';

        $this->post('/setup', [
            'tenant_name' => 'Primary Workspace',
            'admin_name' => 'First Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])->assertRedirect('/');

        $user = User::query()->sole();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertNotSame($password, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check($password, $user->getRawOriginal('password')));

        $membership = TenantMembership::withoutGlobalScopes()->sole();
        $tenant = DB::table('tenants')->where('id', $membership->tenant_id)->first();
        $this->assertNotNull($tenant);

        app(TenantContext::class)->activate(\App\Models\Tenant::query()->findOrFail($membership->tenant_id), $membership);
        $role = $membership->roles()->where('name', 'Owner')->sole();
        $this->assertGreaterThan(0, $role->permissions()->count());
        app(TenantContext::class)->forget();

        $this->get('/setup')->assertRedirect('/');
    }

    public function test_partial_existing_identity_state_is_never_claimed_and_password_is_not_rendered(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        $password = 'never-render-this-password';

        $this->post('/setup', [
            'tenant_name' => 'Attacker Workspace',
            'admin_name' => 'Replacement Owner',
            'admin_email' => 'replacement@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])
            ->assertStatus(400)
            ->assertSee('Setup could not be completed safely')
            ->assertDontSee($password)
            ->assertDontSee('replacement@example.test');

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('tenant_memberships')->count());
    }

    public function test_invalid_password_confirmation_does_not_mutate_identity_state(): void
    {
        $this->post('/setup', [
            'tenant_name' => 'Primary Workspace',
            'admin_name' => 'First Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'correct-horse-battery-staple',
            'admin_password_confirmation' => 'different-password-value',
        ])->assertSessionHasErrors('admin_password');

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
    }
}
