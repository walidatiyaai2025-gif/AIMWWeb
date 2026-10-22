<?php

namespace Tests\Feature;

use App\Http\Controllers\AboutBuildReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\BuildInformationReadService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CurrentUserAboutBuildSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-2387758315';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_current_user_about_build_destination_is_a_read_only_authenticated_tenant_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/about-build', 'GET'));

        $this->assertSame(self::OPERATION_ID, 'AIMW-IDEN-2387758315');
        $this->assertSame(AboutBuildReadController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_guest_missing_permission_and_foreign_tenant_all_fail_closed(): void
    {
        $this->get('/tenants/alpha/about-build')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', []);

        $this->actingAs($limited)->get('/tenants/alpha/about-build')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/foreign/about-build')->assertNotFound();
    }

    public function test_authorized_read_uses_server_tenant_context_and_exposes_only_whitelisted_build_metadata(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        $snapshot = app(BuildInformationReadService::class)->snapshot();
        $secretSentinel = 'must-never-appear-in-about-build-output';
        config()->set('services.test_only.secret', $secretSentinel);

        $response = $this->actingAs($user)->get('/tenants/alpha/about-build?tenant=foreign&user_id=999');

        $response->assertOk()
            ->assertSee('About this build')
            ->assertSee($snapshot['assemblyName'])
            ->assertSee($snapshot['version'])
            ->assertSee($snapshot['informationalVersion'])
            ->assertSee($snapshot['branch'])
            ->assertSee($snapshot['commit'])
            ->assertSee($snapshot['buildTimeUtc'])
            ->assertDontSee($secretSentinel)
            ->assertDontSee('user_id=999');
    }

    public function test_repeated_navigation_is_idempotent_and_creates_no_tenant_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view']);
        $tenantId = $membership->tenant_id;
        $membershipsBefore = TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $this->actingAs($user)->get('/tenants/alpha/about-build')->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/about-build')->assertOk();

        $this->assertSame(
            $membershipsBefore,
            TenantMembership::withoutGlobalScopes()->where('tenant_id', $tenantId)->count(),
            'Read-only About Build navigation must not create or mutate tenant membership state.',
        );
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "current-user-about-build-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
