<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteReliabilityConnectionLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-A93DE991C0';

    public function test_exact_canonical_input_is_the_pending_site_reliability_connection_link(): void
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
        $this->assertSame('/site-reliability | /operations/reliability', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_laravel_bind_open_to_authoritative_reliability_row_site_id(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-reliability-connection-link-control.tsx'));

        $this->assertStringContainsString('href="@($"/sites/{row.SiteId}/connection")"', $source);
        $this->assertStringContainsString('L.IsArabic ? "فتح" : "Open"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('normalizeAuthoritativeSiteId(siteId)', $control);
        $this->assertStringContainsString('tenantUrl(tenantSlug, `/sites/${normalizedSiteId}/connection`)', $control);
        $this->assertStringContainsString('data-canonical-operation={SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID}', $control);
        $this->assertStringContainsString("locale === 'ar' ? 'فتح' : 'Open'", $control);

        $this->assertStringContainsString(
            "import { SiteReliabilityConnectionLinkControl } from './site-reliability-connection-link-control';",
            $pages,
        );
        $this->assertStringContainsString(
            "route.key === 'site-reliability' ? (row) => (",
            $pages,
        );
        $this->assertStringContainsString(
            '<SiteReliabilityConnectionLinkControl tenantSlug={context.tenant.slug} siteId={row.site_id} />',
            $pages,
        );

        $start = strpos($control, 'export function SiteReliabilityConnectionLinkControl');
        $slice = substr($control, $start);
        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('useMutation', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_reliability_read_is_tenant_scoped_and_supplies_the_authoritative_site_id_used_by_open(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha-reliability-link', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Reliability Link Site');
        $this->operation($alpha, $alphaSite, 'connection.test', 'succeeded');

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-reliability-link', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Reliability Link Site');
        $this->operation($beta, $betaSite, 'sync.content', 'failed');

        $this->actingAs($reader)
            ->getJson('/tenants/alpha-reliability-link/route-api/site-reliability')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.site_id', $alphaSite->id)
            ->assertJsonPath('data.0.site_name', 'Alpha Reliability Link Site')
            ->assertJsonMissing(['site_id' => $betaSite->id])
            ->assertJsonMissing(['site_name' => 'Beta Reliability Link Site']);
    }

    public function test_source_surface_and_destination_capability_require_sites_view_and_foreign_tenant_fails_closed(): void
    {
        $core = (string) file_get_contents(resource_path('js/core.ts'));
        $this->assertStringContainsString(
            "r('site-reliability', '/site-reliability'",
            $core,
        );
        $this->assertStringContainsString(
            "r('site-connection-center', '/sites/:siteId/connection'",
            $core,
        );

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-reliability-link', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited-reliability-link/site-reliability')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha-reliability-link-guard', ['tenant.view', 'sites.view']);
        Tenant::query()->create(['name' => 'Foreign Reliability Link', 'slug' => 'foreign-reliability-link']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign-reliability-link/site-reliability')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-reliability-connection-link-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation']['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope']['only_operation_claimed'] ?? null);
        $this->assertFalse((bool) ($evidence['scope']['aspnet_modified'] ?? true));
        $this->assertFalse((bool) ($evidence['scope']['generated_reconciliation_modified'] ?? true));
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name): Site
    {
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function operation(Tenant $tenant, Site $site, string $operation, string $status): SiteOperationHistory
    {
        app(TenantContext::class)->activate($tenant);
        $startedAt = now()->subMinute();
        $row = SiteOperationHistory::query()->create([
            'site_id' => $site->id,
            'correlation_id' => (string) Str::uuid(),
            'operation' => $operation,
            'status' => $status,
            'message' => $status === 'succeeded' ? 'Completed' : 'Failed',
            'details' => ['source' => 'site-reliability-link-test'],
            'affected_records' => 1,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addSecond(),
        ]);
        app(TenantContext::class)->forget();

        return $row;
    }
}
