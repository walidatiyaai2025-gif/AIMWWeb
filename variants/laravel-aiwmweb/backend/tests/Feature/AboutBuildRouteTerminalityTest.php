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

class AboutBuildRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_reconciliation_row_is_the_pending_about_build_route(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', 'AIMW-CONT-81B4B20D2D');

        $this->assertNotNull($row);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('/about-build', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_about_build_and_release_notes_alias_are_explicit_guarded_routes(): void
    {
        foreach (['/tenants/alpha/about-build', '/tenants/alpha/release-notes'] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));

            $this->assertSame(AboutBuildReadController::class, $route->getActionName());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('tenant.context', $route->gatherMiddleware());
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/tenants/alpha/about-build')->assertRedirect('/login');
    }

    public function test_authorized_tenant_member_sees_live_build_metadata_and_truthful_release_empty_state(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'execution.view']);
        $snapshot = app(BuildInformationReadService::class)->snapshot();

        $response = $this->actingAs($user)->get('/tenants/alpha/about-build');

        $response->assertOk()
            ->assertSee('About this build')
            ->assertSee($snapshot['assemblyName'])
            ->assertSee($snapshot['version'])
            ->assertSee($snapshot['informationalVersion'])
            ->assertSee($snapshot['branch'])
            ->assertSee($snapshot['commit'])
            ->assertSee($snapshot['buildTimeUtc'])
            ->assertSee('No release notes were found.')
            ->assertSee('/api/build');

        $this->actingAs($user)->get('/tenants/alpha/release-notes')
            ->assertOk()
            ->assertSee('About this build')
            ->assertSee('No release notes were found.');

        $this->actingAs($user)->getJson('/api/build')
            ->assertOk()
            ->assertExactJson($snapshot);
    }

    public function test_route_fails_closed_for_missing_permission_and_foreign_tenant(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['execution.view']);

        $this->actingAs($limited)->get('/tenants/alpha/about-build')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/foreign/about-build')->assertNotFound();
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
        $role = Role::query()->create(['name' => "about-build-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
