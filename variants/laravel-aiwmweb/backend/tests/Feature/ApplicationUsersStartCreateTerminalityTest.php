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

final class ApplicationUsersStartCreateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-E38ADF8481';

    public function test_source_semantics_are_wired_to_a_real_tenant_scoped_create_form(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/application-users-start-create-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="StartCreateClicked"', $source);
        $this->assertStringContainsString('private Task StartCreateClicked', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.permissions.includes('members.manage')", $control);
        $this->assertStringContainsString("context.api['application-users']", $control);
        $this->assertStringContainsString('apiRequest(membersEndpoint', $control);
        $this->assertStringContainsString('ApplicationUsersStartCreateControl', $pages);
        $this->assertStringContainsString("route.key === 'application-users'", $pages);

        $this->assertStringNotContainsString('AIMW-SYNC-EC958CD950', $control);
        $this->assertStringNotContainsString('AIMW-SYNC-26F2BF8EE8', $control);
        $this->assertStringNotContainsString('AIMW-SYNC-6FCFE15D24', $control);
    }

    public function test_authorized_member_can_use_the_real_member_endpoint_and_authoritative_reread(): void
    {
        $actor = User::factory()->create(['email' => 'owner@example.test']);
        $tenant = $this->membership($actor, 'alpha', ['tenant.view', 'users.view', 'members.manage']);

        $target = User::factory()->create(['email' => 'member@example.test']);
        $role = $this->tenantRole($tenant, 'Editor');

        $this->actingAs($actor)
            ->postJson('/tenants/alpha/admin/members', [
                'email' => 'member@example.test',
                'role_id' => $role->id,
            ])
            ->assertCreated()
            ->assertJsonPath('kind', 'member');

        $membership = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $target->id)
            ->firstOrFail();

        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->roles()->whereKey($role->id)->exists());

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members')
            ->assertOk()
            ->assertJsonFragment(['email' => 'member@example.test']);
    }

    public function test_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $actor = User::factory()->create();
        $this->membership($actor, 'alpha', ['tenant.view', 'users.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($actor)
            ->postJson('/tenants/alpha/admin/members', ['email' => 'blocked@example.test'])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson('/tenants/beta/admin/members', ['email' => 'foreign@example.test'])
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $role = Role::query()->create(['name' => "start-create-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function tenantRole(Tenant $tenant, string $name): Role
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $role = Role::query()->create(['name' => $name]);
        $context->forget();

        return $role;
    }
}
