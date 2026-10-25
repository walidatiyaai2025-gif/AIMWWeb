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

final class ContentExplorerDynamicLinksTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9BA2C255AF';

    public function test_exact_canonical_input_is_the_pending_dynamic_href_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/explorer', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_dynamic_hrefs_and_laravel_runtime_are_bound_to_real_routes(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/content-explorer-dynamic-links-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('href="@($"/sites/{Id}")"', $source);
        $this->assertStringContainsString('href="@($"/sites/{Id}/taxonomy")"', $source);
        $this->assertStringContainsString('href="@($"/sites/{Id}/seo")"', $source);
        $this->assertStringContainsString('/content/{item.ContentType}/{item.WordPressId}/edit', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('active_site', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug", $control);
        $this->assertStringContainsString('/module/taxonomy?site=', $control);
        $this->assertStringContainsString('/module/seo-audit?site=', $control);
        $this->assertStringContainsString('/content/', $control);
        $this->assertStringContainsString('ContentExplorerDynamicLinksControl context={context}', $app);
    }

    public function test_authorized_explorer_is_real_and_foreign_tenant_site_fails_closed_with_404(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership(
            $alphaUser,
            'alpha-dynamic',
            ['tenant.view', 'sites.view', 'content.view', 'seo.view'],
        );
        $alphaSite = $this->site($alphaMembership->tenant, 'Alpha Dynamic');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership(
            $betaUser,
            'beta-dynamic',
            ['tenant.view', 'sites.view', 'content.view', 'seo.view'],
        );
        $betaSite = $this->site($betaMembership->tenant, 'Beta Dynamic');

        $this->withoutVite();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha-dynamic/explorer?site='.$alphaSite->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha-dynamic/explorer?site='.$betaSite->id)
            ->assertNotFound();

        $this->assertNotSame($alphaMembership->tenant_id, $betaMembership->tenant_id);
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
        $role = Role::query()->create([
            'name' => "content-explorer-dynamic-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
