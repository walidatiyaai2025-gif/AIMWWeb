<?php

namespace Tests\Feature;

use App\Connector\WordPressGateway;
use App\Http\Controllers\DemoController;
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
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class SiteDetailsSaveAndTestHandlerTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-C37235F06A';

    public function test_site_details_save_and_test_source_and_laravel_control_bind_the_real_recheck_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteDetails.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}"', $source);
        $this->assertStringContainsString('OnSubmit="SaveAndTestAsync"', $source);
        $this->assertStringContainsString('SiteService.SaveCredentialAndTestAsync(Id', $source);

        $frontend = (string) file_get_contents(resource_path('js/site-details-save-test-control.tsx'));
        $wiring = (string) file_get_contents(resource_path('js/site-details-back-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('/verify', $frontend);
        $this->assertStringContainsString('connector.manage', $frontend);
        $this->assertStringContainsString('mutateThenReconcile', $frontend);
        $this->assertStringContainsString('SiteDetailsSaveTestControl', $wiring);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/verify', 'POST'));
        $this->assertSame(DemoController::class.'@verify', ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.api.site-details.save-and-test', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_site_details_save_and_test_fails_closed_for_foreign_tenant_and_missing_authority(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($alpha);
        Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Site', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/verify", [])
            ->assertNotFound();

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma', ['tenant.view', 'sites.view']);
        $this->activate($gamma);
        $gammaSite = Site::query()->create(['name' => 'Gamma Site', 'url' => 'https://gamma.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($viewer)
            ->postJson("/api/tenants/gamma/sites/{$gammaSite->id}/verify", [])
            ->assertForbidden();
    }

    public function test_save_and_test_persists_authoritative_verification_and_rejects_caller_success(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($alpha);
        $site = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $gateway = Mockery::mock(WordPressGateway::class);
        $gateway->shouldReceive('health')->once()->with(Mockery::on(fn (Site $candidate): bool => $candidate->id === $site->id && $candidate->tenant_id === $alpha->id))
            ->andReturn(['status' => 'degraded']);
        $this->app->instance(WordPressGateway::class, $gateway);

        $this->actingAs($manager)->postJson("/api/tenants/alpha/sites/{$site->id}/verify", ['tenant_id' => 999, 'status' => 'healthy'])
            ->assertOk()->assertJsonPath('status', 'degraded');
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $alpha->id, 'connection_status' => 'verified', 'health_state' => 'degraded']);
        $this->actingAs($manager)->getJson("/api/tenants/alpha/sites/{$site->id}")
            ->assertOk()->assertJsonPath('connection_status', 'verified');
    }

    public function test_failed_external_verification_does_not_persist_a_success_state(): void
    {
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'connector.manage']);
        $this->activate($alpha);
        $site = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active', 'connection_status' => 'disconnected']);
        app(TenantContext::class)->forget();
        $gateway = Mockery::mock(WordPressGateway::class);
        $gateway->shouldReceive('health')->once()->andThrow(new RuntimeException('Remote verification unavailable'));
        $this->app->instance(WordPressGateway::class, $gateway);
        $this->actingAs($manager)->postJson("/api/tenants/alpha/sites/{$site->id}/verify", [])->assertStatus(500);
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $alpha->id, 'connection_status' => 'disconnected', 'last_verified_at' => null]);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-site-details-save-test-'.$user->id]);

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
