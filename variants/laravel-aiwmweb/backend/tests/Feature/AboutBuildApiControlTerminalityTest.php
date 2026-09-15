<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\BuildInformationReadService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutBuildApiControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_canonical_row_is_the_pending_open_build_api_visible_control(): void
    {
        $row = $this->canonicalRow('AIMW-CONT-EBD53650BC');

        $this->assertNotNull($row);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('/about-build | /release-notes', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor', $row['current_source']);
        $this->assertStringContainsString('/api/build', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
    }

    public function test_visible_control_is_tenant_qualified_and_opens_the_authoritative_build_read(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'execution.view']);
        $this->membership($user, 'beta', ['tenant.view', 'execution.view']);
        $snapshot = app(BuildInformationReadService::class)->snapshot();

        foreach (['about-build', 'release-notes'] as $sourceRoute) {
            $this->actingAs($user)->get("/tenants/alpha/{$sourceRoute}")
                ->assertOk()
                ->assertSee('Open build API')
                ->assertSee('href="/api/build?tenant=alpha"', false)
                ->assertSee('target="_blank"', false)
                ->assertSee('rel="noopener noreferrer"', false)
                ->assertDontSee('href="/api/build?tenant=beta"', false);
        }

        $this->actingAs($user)->getJson('/api/build?tenant=alpha')
            ->assertOk()
            ->assertExactJson($snapshot);
    }

    public function test_control_fails_closed_when_api_permission_is_missing(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);

        $this->actingAs($user)->get('/tenants/alpha/about-build')
            ->assertOk()
            ->assertDontSee('Open build API')
            ->assertSee('/api/build (permission required)');

        $this->actingAs($user)->getJson('/api/build?tenant=alpha')->assertForbidden();
    }

    public function test_guest_and_cross_tenant_direct_access_fail_closed(): void
    {
        $this->get('/tenants/alpha/about-build')->assertRedirect('/login');
        $this->getJson('/api/build?tenant=alpha')->assertUnauthorized();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->membership($userA, 'alpha', ['tenant.view', 'execution.view']);
        $this->membership($userB, 'beta', ['tenant.view', 'execution.view']);

        $this->actingAs($userA)->get('/tenants/beta/about-build')->assertNotFound();
        $this->actingAs($userA)->getJson('/api/build?tenant=beta')->assertNotFound();
    }

    private function canonicalRow(string $operationId): ?array
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($payload['operations'])->firstWhere('operation_id', $operationId);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "about-build-api-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
