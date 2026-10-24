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

final class SitesUrlLinkVisibleControlTerminalityTest extends TestCase
{
    // Exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-44A3B8E551';

    public function test_exact_canonical_operation_matches_source_site_url_link_semantics(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertStringContainsString('@site.SiteUrl', (string) $operation['visible_control']);
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

    public function test_laravel_binds_the_operation_to_safe_authoritative_external_navigation_only(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('canonicalExternalSiteUrl', $frontend);
        $this->assertStringContainsString("candidate.protocol === 'http:' || candidate.protocol === 'https:'", $frontend);
        $this->assertStringContainsString('data-canonical-operation={SITES_URL_LINK_OPERATION_ID}', $frontend);
        $this->assertStringContainsString('target="_blank"', $frontend);
        $this->assertStringContainsString('rel="noopener noreferrer"', $frontend);
        $this->assertStringContainsString('<SitesUrlLinkControl value={row.url} />', $frontend);

        $start = strpos($frontend, 'export function SitesUrlLinkControl');
        $end = strpos($frontend, 'function endpointWithQuery', $start);
        $slice = substr($frontend, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('useMutation', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_sites_surface_inherits_active_tenant_and_sites_view_authorization(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha-url-link', ['tenant.view', 'sites.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha-url-link/sites')
            ->assertOk();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-url-link', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-url-link/sites')
            ->assertForbidden();

        Tenant::query()->create(['name' => 'Foreign Url Link', 'slug' => 'foreign-url-link']);

        $this->actingAs($user)
            ->get('/tenants/foreign-url-link/sites')
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
            'name' => "sites-url-link-{$slug}-{$user->id}",
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
