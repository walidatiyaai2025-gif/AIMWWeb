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

final class SiteSettingsDeliveryHistoryLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-01F5713C4F';

    public function test_exact_canonical_operation_matches_site_settings_delivery_history_navigation(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('email', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/sites/{Id:guid}/settings', $row['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> /email/history', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/SiteSettingsReadController.php'));

        $this->assertStringContainsString('Href="/email/history"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString("route('canonical.workspace.email-history'", $view);
        $this->assertStringContainsString('canViewEmailHistory', $controller);
        $this->assertStringContainsString("hasPermission('tenant.manage')", $controller);
        $this->assertStringContainsString("hasPermission('diagnostics.view')", $controller);
    }

    public function test_authorized_member_gets_tenant_scoped_delivery_history_link(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-site-mail', [
            'tenant.view',
            'sites.view',
            'tenant.manage',
            'diagnostics.view',
        ]);
        $site = $this->site($membership, 'Alpha Mail Site');

        $this->actingAs($user)
            ->get('/tenants/alpha-site-mail/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee(self::OPERATION_ID, false)
            ->assertSee('/tenants/alpha-site-mail/email/history', false);

        $this->actingAs($user)
            ->get('/tenants/alpha-site-mail/email/history')
            ->assertOk();
    }

    public function test_missing_history_permission_hides_control_and_destination_fails_closed(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited-site-mail', [
            'tenant.view',
            'sites.view',
            'tenant.manage',
        ]);
        $site = $this->site($membership, 'Limited Mail Site');

        $this->actingAs($user)
            ->get('/tenants/limited-site-mail/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID, false)
            ->assertDontSee('/tenants/limited-site-mail/email/history', false);

        $this->actingAs($user)
            ->get('/tenants/limited-site-mail/email/history')
            ->assertForbidden();
    }

    public function test_foreign_tenant_site_settings_and_history_are_not_available(): void
    {
        $this->withoutVite();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha-mail-foreign', [
            'tenant.view',
            'sites.view',
            'tenant.manage',
            'diagnostics.view',
        ]);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-mail-foreign', [
            'tenant.view',
            'sites.view',
            'tenant.manage',
            'diagnostics.view',
        ]);
        $betaSite = $this->site($beta, 'Beta Mail Site');

        $this->actingAs($alphaUser)
            ->get('/tenants/beta-mail-foreign/sites/'.$betaSite->id.'/settings')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta-mail-foreign/email/history')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'site-mail-history-'.$slug.'-'.$user->id]);

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
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }
}
