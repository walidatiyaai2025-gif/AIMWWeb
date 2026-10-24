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

final class SiteSettingsToolbarNavigationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-0154E7772B';

    public function test_exact_canonical_input_is_the_pending_site_settings_toolbar_navigation_control(): void
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
        $this->assertSame('/sites/{Id:guid}/settings', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_laravel_render_the_two_source_toolbar_destinations_from_authoritative_site_context(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));

        $this->assertStringContainsString('Href="@($"/sites/{Id}")"', $source);
        $this->assertStringContainsString('Site dashboard', $source);
        $this->assertStringContainsString('Href="@($"/sites/{Id}/connection")"', $source);
        $this->assertStringContainsString('Connection center', $source);

        $this->assertSame(2, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
        $this->assertStringContainsString('aria-label="Site settings shortcuts"', $view);
        $this->assertStringContainsString("route('canonical.site.details'", $view);
        $this->assertStringContainsString('/tenants/{{ rawurlencode($tenant) }}/sites/{{ $site->getKey() }}/connection', $view);
        $this->assertStringContainsString("'لوحة الموقع' : 'Site dashboard'", $view);
        $this->assertStringContainsString("'مركز الاتصال' : 'Connection center'", $view);
    }

    public function test_authorized_settings_read_renders_exact_tenant_site_links_without_mutating_site_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');

        $before = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only([
            'name',
            'url',
            'status',
            'connection_status',
        ]);

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('aria-label="Site settings shortcuts"', false)
            ->assertSee('Site dashboard')
            ->assertSee('Connection center')
            ->assertSee('href="/tenants/alpha/sites/'.$site->id.'"', false)
            ->assertSee('href="/tenants/alpha/sites/'.$site->id.'/connection"', false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-canonical-operation="'.self::OPERATION_ID.'"'),
        );

        $after = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only([
            'name',
            'url',
            'status',
            'connection_status',
        ]);
        $this->assertSame($before, $after);
    }

    public function test_toolbar_labels_follow_the_active_locale_without_changing_destinations(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');
        app()->setLocale('ar');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('لوحة الموقع')
            ->assertSee('مركز الاتصال')
            ->assertSee('href="/tenants/alpha/sites/'.$site->id.'"', false)
            ->assertSee('href="/tenants/alpha/sites/'.$site->id.'/connection"', false);
    }

    public function test_missing_sites_view_and_foreign_site_fail_closed(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.example.test');

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/settings')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.example.test');
        $betaSite = $this->site($beta, 'Beta Site', 'https://beta.example.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/settings')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/settings')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-settings-toolbar-navigation-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation']['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['operation']['terminal_state'] ?? null);
        $this->assertSame([self::OPERATION_ID], $evidence['scope_guards']['claimed_operations'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['sibling_localized_submit_control_claimed'] ?? true));
        $this->assertFalse((bool) ($evidence['scope_guards']['aspnet_product_modified'] ?? true));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-settings-toolbar-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        $context->forget();

        return $site;
    }
}
