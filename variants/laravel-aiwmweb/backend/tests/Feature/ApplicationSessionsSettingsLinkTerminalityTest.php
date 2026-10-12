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

final class ApplicationSessionsSettingsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-F9A87DD98C';

    public function test_admin_sessions_renders_exact_canonical_settings_sessions_link(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationSessions.razor'),
        );

        $this->assertStringContainsString('href="/settings/sessions"', $source);

        [$tenant, $membership] = $this->tenantWithUser('alpha', [
            'tenant.view',
            'members.manage',
            'sessions.manage',
            'sessions.view',
        ]);

        $this->actingAs($membership->user)
            ->get("/tenants/{$tenant->slug}/admin/sessions")
            ->assertOk()
            ->assertSee('href="/tenants/alpha/settings/sessions"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('My sessions');
    }

    public function test_destination_is_real_tenant_scoped_alias_and_fails_closed(): void
    {
        [$alpha, $authorized] = $this->tenantWithUser('alpha', [
            'tenant.view',
            'members.manage',
            'sessions.manage',
            'sessions.view',
        ]);
        [$beta] = $this->tenantWithUser('beta', [
            'tenant.view',
            'members.manage',
            'sessions.manage',
            'sessions.view',
        ]);
        [, $limited] = $this->tenantWithUser('limited', [
            'tenant.view',
            'members.manage',
        ]);

        $this->actingAs($authorized->user)
            ->get("/tenants/{$alpha->slug}/settings/sessions")
            ->assertRedirect("/tenants/{$alpha->slug}/account/sessions");

        $this->actingAs($authorized->user)
            ->get("/tenants/{$beta->slug}/settings/sessions")
            ->assertNotFound();

        $this->actingAs($limited->user)
            ->get('/tenants/limited/settings/sessions')
            ->assertForbidden();
    }

    private function tenantWithUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create(['email' => "{$slug}-sessions-link@example.test"]);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->setRelation('user', $user);

        $role = Role::query()->create(['name' => "sessions-link-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $membership];
    }
}
