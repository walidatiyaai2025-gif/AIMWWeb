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

class CurrentUserBuildInformationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-2387758315';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_cannot_open_current_user_build_information_destination(): void
    {
        $this->assertSame('AIMW-IDEN-2387758315', self::OPERATION_ID);

        $this->get('/tenants/alpha/about-build')->assertRedirect('/login');
    }

    public function test_member_without_tenant_view_is_forbidden(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/about-build')
            ->assertForbidden();
    }

    public function test_foreign_tenant_destination_is_rejected_before_build_metadata_is_rendered(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);

        $this->actingAs($user)
            ->get('/tenants/foreign/about-build')
            ->assertNotFound();
    }

    public function test_authorized_member_reads_real_safe_build_metadata_without_secret_material(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        $snapshot = app(BuildInformationReadService::class)->snapshot();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/about-build')
            ->assertOk()
            ->assertSee('About this build')
            ->assertSee($snapshot['version'])
            ->assertSee($snapshot['branch'])
            ->assertSee($snapshot['commit'])
            ->assertSee($snapshot['buildTimeUtc']);

        $response->assertDontSee('APP_KEY', false)
            ->assertDontSee('DB_PASSWORD', false)
            ->assertDontSee('AWS_SECRET_ACCESS_KEY', false);
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
        $role = Role::query()->create(['name' => "current-user-build-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
