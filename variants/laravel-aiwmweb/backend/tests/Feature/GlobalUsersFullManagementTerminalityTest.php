<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalUsersFullManagementTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B9E6EFF3DF';

    public function test_exact_canonical_input_is_the_pending_global_users_full_management_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/users', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> @($', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalUsersWorkspace.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_and_laravel_bind_full_management_to_the_authoritative_active_site(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalUsersWorkspace.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/global-users-full-management-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('Href="@($"/sites/{_selectedSiteId}/users")"', $source);
        $this->assertStringContainsString('L.IsArabic ? "الإدارة الكاملة" : "Full user management"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("context.api[\`sites.detail.\${activeSite.id}\`]", $control);
        $this->assertStringContainsString("context.api['wp-users']", $control);
        $this->assertStringContainsString("tenantUrl(tenantSlug, \`/sites/\${activeSite.id}/users\`)", $control);
        $this->assertStringContainsString('data-canonical-operation={GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID}', $control);
        $this->assertStringContainsString("locale === 'ar' ? 'الإدارة الكاملة' : 'Full user management'", $control);

        $this->assertStringContainsString(
            "import { GlobalUsersFullManagementControl } from './global-users-full-management-control';",
            $pages,
        );
        $this->assertStringContainsString(
            "if (route.key === 'wp-users') return <><GlobalUsersFullManagementControl context={context} route={route} /><ResourceContent context={context} route={route} /></>;",
            $pages,
        );

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_server_binding_supplies_the_authoritative_site_and_real_wordpress_users_api(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-global-users', ['tenant.view', 'sites.view']);
        $site = $this->site($alpha, 'Alpha Global Users');

        $this->actingAs($user)
            ->get("/tenants/alpha-global-users/sites/{$site->id}/users")
            ->assertRedirect("/tenants/alpha-global-users/module/users?site={$site->id}");

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha-global-users/context')
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('active_site.name', 'Alpha Global Users');

        $payload = $response->json();
        $this->assertSame(
            "/api/tenants/alpha-global-users/sites/{$site->id}",
            $payload['api']["sites.detail.{$site->id}"],
        );
        $this->assertSame(
            "/api/v1/tenants/alpha-global-users/sites/{$site->id}/users",
            $payload['api']['wp-users'],
        );
    }

    public function test_destination_rejects_missing_permission_and_foreign_tenant_site(): void
    {
        $authorized = User::factory()->create();
        $alpha = $this->membership($authorized, 'alpha-users-guard', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Users Guard');

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-users-guard', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Users Guard');

        $limited = User::factory()->create();
        $limitedTenant = $this->membership($limited, 'limited-users-guard', ['tenant.view']);
        $limitedSite = $this->site($limitedTenant, 'Limited Users Guard');

        $this->actingAs($limited)
            ->get("/tenants/limited-users-guard/sites/{$limitedSite->id}/users")
            ->assertForbidden();

        $this->actingAs($authorized)
            ->get("/tenants/alpha-users-guard/sites/{$alphaSite->id}/users")
            ->assertRedirect("/tenants/alpha-users-guard/module/users?site={$alphaSite->id}");

        $this->actingAs($authorized)
            ->get("/tenants/alpha-users-guard/sites/{$betaSite->id}/users")
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/global-users-full-management-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation_claimed'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['aspnet_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope_guards']['generated_reconciliation_hand_edited'] ?? true));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "{$slug}-role-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.str($name)->slug().'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
