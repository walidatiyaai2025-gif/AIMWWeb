<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteDataSnapshotBackToSitesControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-CA4920CBC8';

    public function test_exact_canonical_input_is_the_pending_snapshot_back_to_sites_control(): void
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
        $this->assertSame('/sites/{Id:guid}/snapshot | /sites/{Id:guid}/offline-data', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteDataSnapshot.razor', $operation['current_source']);
        $this->assertSame('@(L.IsArabic ? -> /sites', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_sites_destination_is_real_tenant_guarded_read_only_workspace(): void
    {
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
    }

    public function test_missing_snapshot_site_and_foreign_site_fail_closed_while_sites_recovery_remains_read_only(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $before = Site::withoutGlobalScopes()->findOrFail($alphaSite->id)->toArray();

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/alpha/sites/999999/snapshot')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/tenants/alpha/sites/{$betaSite->id}/snapshot")
            ->assertNotFound();

        $this->withoutVite();
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $after = Site::withoutGlobalScopes()->findOrFail($alphaSite->id)->toArray();
        $this->assertSame($before, $after, 'Back to sites recovery navigation must not mutate Site persistence.');
    }

    public function test_guest_and_missing_sites_permission_fail_closed(): void
    {
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view']);
        $this->activate($tenant);
        Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($user)->get('/tenants/alpha/sites')->assertForbidden();
    }

    public function test_production_wiring_matches_source_missing_site_recovery_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteDataSnapshot.razor'));
        $control = (string) file_get_contents(resource_path('js/site-data-snapshot-back-to-sites-control.tsx'));
        $snapshot = (string) file_get_contents(resource_path('js/site-data-snapshot-sync-control.tsx'));

        $this->assertStringContainsString('else if (_site is null)', $source);
        $this->assertStringContainsString('Href="/sites"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $control);
        $this->assertStringContainsString("hasPermission(context, 'sites.view')", $control);
        $this->assertStringContainsString('cause instanceof ApiError && cause.status === 404', $snapshot);
        $this->assertStringContainsString('SiteDataSnapshotBackToSitesControl', $snapshot);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('method:', $control);
    }

    public function test_terminal_closure_evidence_is_operation_scoped_and_adapted(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-data-snapshot-back-to-sites-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('PENDING', $evidence['canonical_operation']['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation_claimed'] ?? null);
        $this->assertTrue((bool) ($evidence['terminality']['focused_tests_present'] ?? false));
        $this->assertTrue((bool) ($evidence['terminality']['evidence_recorded'] ?? false));
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "snapshot-back-sites-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
