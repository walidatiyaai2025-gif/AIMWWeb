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

final class SiteConnectionBackToSitesTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-8C19E4E3B2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_operation_matches_source_back_to_sites_control(): void
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
        $this->assertSame('/sites/{Id:guid}/connection | /sites/{Id:guid}/operations', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ? -> /sites', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_production_wiring_preserves_source_not_found_only_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('AppEmptyState Icon="◎"', $source);
        $this->assertStringContainsString('AppButton Href="/sites"', $source);
        $this->assertStringContainsString('Back to sites', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('cause instanceof ApiError && cause.status === 404', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $control);
        $this->assertStringContainsString('data-testid="site-connection-back-to-sites"', $control);
        $this->assertStringContainsString(
            '<SiteConnectionCenterSyncControl context={context} />',
            $app,
        );
    }

    public function test_sites_destination_is_authenticated_tenant_scoped_and_read_only(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/beta/sites')
            ->assertNotFound();

        $this->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->getJson('/api/tenants/alpha/sites')
            ->assertUnauthorized();
    }

    public function test_missing_site_read_fails_closed_without_mutating_tenant_state(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beforeMemberships = TenantMembership::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/999999/connection')
            ->assertNotFound();

        $this->assertSame($beforeMemberships, TenantMembership::withoutGlobalScopes()->count());
        $this->assertSame($tenant->id, Tenant::query()->where('slug', 'alpha')->value('id'));
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
        $role = Role::query()->create(['name' => "site-back-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
