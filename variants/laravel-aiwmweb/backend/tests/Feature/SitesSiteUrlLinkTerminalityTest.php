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

final class SitesSiteUrlLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-44A3B8E551';

    public function test_exact_canonical_operation_matches_source_site_url_link(): void
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
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertSame('@site.SiteUrl -> @site.SiteUrl', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Sites.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );

        $this->assertStringContainsString(
            '<a href="@site.SiteUrl" target="_blank" rel="noopener">@site.SiteUrl</a>',
            $source,
        );
    }

    public function test_laravel_renders_the_authoritative_site_url_without_extra_mutation(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));
        $table = (string) file_get_contents(resource_path('js/components.tsx'));
        $service = (string) file_get_contents(app_path('Sites/SiteManagementService.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("'site_url'", $frontend);
        $this->assertStringContainsString("typeof row.site_url === 'string'", $frontend);
        $this->assertStringContainsString('href={siteUrl}', $frontend);
        $this->assertStringContainsString('target="_blank"', $frontend);
        $this->assertStringContainsString('rel="noopener"', $frontend);
        $this->assertStringContainsString('data-canonical-operation={SITES_SITE_URL_OPERATION_ID}', $frontend);
        $this->assertStringContainsString('renderCell?: (row: Record<string, unknown>, column: string, formatted: string)', $table);
        $this->assertStringContainsString("'site_url' => (string) \$site->url", $service);

        $start = strpos($frontend, "renderCell={route.key === 'sites'");
        $end = strpos($frontend, "renderActions={route.key === 'posts'", $start);
        $slice = substr($frontend, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_sites_view_permission_and_tenant_boundary_remain_fail_closed(): void
    {
        $allowed = User::factory()->create();
        $this->membership($allowed, 'alpha-url', ['tenant.view', 'sites.view']);
        $this->withoutVite();

        $this->actingAs($allowed)
            ->get('/tenants/alpha-url/sites')
            ->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-url', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-url/sites')
            ->assertForbidden();

        Tenant::query()->create(['name' => 'Foreign URL', 'slug' => 'foreign-url']);

        $this->actingAs($allowed)
            ->get('/tenants/foreign-url/sites')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "sites-site-url-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
