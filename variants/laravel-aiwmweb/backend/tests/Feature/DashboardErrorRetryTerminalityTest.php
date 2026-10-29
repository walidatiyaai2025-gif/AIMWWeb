<?php

namespace Tests\Feature;

use App\Http\Controllers\PlatformReadController;
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

final class DashboardErrorRetryTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-B11D963A9E';

    public function test_canonical_identity_and_error_state_retry_wiring_match_the_original_source(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/', $row['route_screen']);
        $this->assertSame('@(L.IsArabic ? [RefreshClickedAsync]', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Home.razor'));
        $control = (string) file_get_contents(resource_path('js/dashboard-error-retry-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('Try again', $source);
        $this->assertStringContainsString('RefreshClickedAsync', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('inFlight.current', $control);
        $this->assertStringContainsString('await onRetry()', $control);
        $this->assertStringContainsString('if (query.error) return <QueryError', $pages);
        $this->assertStringContainsString('<DashboardErrorRetryControl', $pages);
        $this->assertStringContainsString('onRetry={() => query.refetch()}', $pages);
        $this->assertStringContainsString("queryKey: ['dashboard', context.tenant.slug, endpoint]", $pages);
        $this->assertStringContainsString('retryAction={', $pages);
    }

    public function test_retry_uses_existing_real_read_only_dashboard_controller_and_new_server_snapshot(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['execution.view']);

        $route = Route::getRoutes()->match(Request::create('/api/dashboard?tenant=alpha', 'GET'));
        $this->assertSame(PlatformReadController::class.'@dashboard', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());

        $this->actingAs($user)->getJson('/api/dashboard?tenant=alpha')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 0);

        // A change to authoritative tenant data between attempts is observed by
        // the same server endpoint, not by a locally fabricated success state.
        app(TenantContext::class)->activate($membership->tenant, $membership);
        Site::query()->create([
            'name' => 'New Site',
            'url' => 'https://new-site.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
            'health_state' => 'healthy',
        ]);
        app(TenantContext::class)->forget();

        $before = Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();

        $this->actingAs($user)->getJson('/api/dashboard?tenant=alpha')
            ->assertOk()
            ->assertJsonPath('sites.totalSites', 1)
            ->assertJsonPath('sites.connectedSites', 1);

        $this->assertSame($before, Site::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray());
    }

    public function test_guest_wrong_permission_and_foreign_tenant_retry_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['execution.view']);
        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['execution.view']);
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->getJson('/api/dashboard?tenant=alpha')->assertUnauthorized();

        $this->actingAs($alphaUser)
            ->getJson('/api/dashboard?tenant=beta')
            ->assertNotFound();

        $this->actingAs($limited)
            ->getJson('/api/dashboard?tenant=limited')
            ->assertForbidden();
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
        $role = Role::query()->create(['name' => 'dashboard-retry-'.$slug.'-'.$user->id]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
