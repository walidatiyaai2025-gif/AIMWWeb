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

class SitesSiteUrlLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-44A3B8E551';

    public function test_exact_canonical_input_is_the_pending_sites_site_url_control(): void
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
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_laravel_bind_the_real_authoritative_site_url_link(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/sites-site-url-link-control.tsx'));

        $this->assertStringContainsString(
            '<a href="@site.SiteUrl" target="_blank" rel="noopener">@site.SiteUrl</a>',
            $source,
        );
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('{safeUrl}', $control);
        $this->assertStringContainsString("import { SitesSiteUrlLinkControl } from './sites-site-url-link-control';", $pages);
        $this->assertStringContainsString('<SitesSiteUrlLinkControl value={row.site_url ?? row.url} />', $pages);
    }

    public function test_sites_view_surface_is_tenant_scoped_and_returns_only_authoritative_rows(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha site', 'https://alpha.example.test');
        $this->site($beta, 'Beta site', 'https://beta.example.test');

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $alphaSite->id,
                'name' => 'Alpha site',
                'url' => 'https://alpha.example.test',
            ])
            ->assertJsonMissing([
                'name' => 'Beta site',
                'url' => 'https://beta.example.test',
            ]);
    }

    public function test_missing_sites_view_is_forbidden_and_foreign_workspace_fails_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/sites')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->get('/tenants/beta/sites')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/sites-site-url-link-terminality.json')),
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

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "sites-site-url-{$slug}-{$user->id}"]);

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
            'connection_status' => 'connected',
        ]);
        $context->forget();

        return $site;
    }
}
