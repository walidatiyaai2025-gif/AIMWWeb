<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Http\Controllers\SiteDiagnosticsController;
use App\Jobs\SyncSiteJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteConnectionCenterSynchronizeTerminalityTest extends TestCase
{
    // Exact-head CI anchor after tenant/site route binding fix and official AIMW-BILL-3762C05261 materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3762C05261';

    public function test_source_and_laravel_runtime_bind_exact_connection_center_sync_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('@onclick="SynchronizeAsync"', $source);
        $this->assertStringContainsString('_lastSync = await SyncService.SynchronizeAsync(Id);', $source);
        $this->assertStringContainsString('History.Record(_ownerUserId, Id, "synchronization"', $source);
        $this->assertStringContainsString('_history = History.Get(_ownerUserId, [Id], Id).ToList();', $source);

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('/connection', $frontend);
        $this->assertStringContainsString('/operations?take=100', $frontend);
        $this->assertStringContainsString('/sync', $frontend);
        $this->assertStringContainsString('Idempotency-Key', $frontend);

        $job = (string) file_get_contents(app_path('Jobs/SyncSiteJob.php'));
        $this->assertStringContainsString('SiteOperationHistoryService $history', $job);
        $this->assertSame(2, substr_count($job, '$history->record('));
        $this->assertStringContainsString("'synchronization'", $job);
        $this->assertStringContainsString("'WordPress synchronization completed.'", $job);
        $this->assertStringContainsString("'WordPress synchronization failed.'", $job);
        $this->assertStringContainsString("['sync_run_id' => (int) \$run->getKey()]", $job);

        $connection = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($connection->getActionName(), '\\'));
        $this->assertContains('auth', $connection->gatherMiddleware());
        $this->assertContains('tenant.context', $connection->gatherMiddleware());

        $operations = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/operations', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@operations', ltrim($operations->getActionName(), '\\'));
        $this->assertContains('auth', $operations->gatherMiddleware());
        $this->assertContains('tenant.context', $operations->gatherMiddleware());

        $sync = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/sync', 'POST'));
        $this->assertSame(DemoController::class.'@sync', ltrim($sync->getActionName(), '\\'));
        $this->assertContains('web', $sync->gatherMiddleware());
        $this->assertContains('auth', $sync->gatherMiddleware());
        $this->assertContains('tenant.context', $sync->gatherMiddleware());
    }

    public function test_guest_permission_and_foreign_site_fail_closed_without_dispatch(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $reader = User::factory()->create();
        $this->membershipExisting($reader, $alpha, ['tenant.view', 'sites.view'], 'alpha-reader');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertUnauthorized();

        $this->actingAs($reader)
            ->withHeader('Idempotency-Key', 'connection-reader-denied')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertForbidden();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'connection-foreign-site')
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_sync_reuses_durable_run_and_connection_reads_remain_tenant_authoritative(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($tenant);
        $site = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $key = 'connection-sync-22222222-2222-4222-8222-222222222222';

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$site->id}/sync")
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');

        $runId = (int) $first->json('id');
        $this->assertGreaterThan(0, $runId);
        Queue::assertPushed(SyncSiteJob::class, 1);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$site->id}/sync")
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        Queue::assertPushed(SyncSiteJob::class, 1);

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/connection")
            ->assertOk()
            ->assertJsonPath('site.id', $site->id)
            ->assertJsonPath('site.name', 'Alpha Site')
            ->assertJsonMissingPath('provider_secret')
            ->assertJsonMissingPath('application_password');

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/not-a-number/connection')
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/not-a-number/operations')
            ->assertNotFound();

        $this->activate($tenant);
        app(SiteOperationHistoryService::class)->record(
            $site->id,
            'synchronization',
            true,
            'WordPress synchronization completed.',
            ['sync_run_id' => $runId],
            4,
        );
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/operations?take=100")
            ->assertOk()
            ->assertJsonPath('items.0.operation', 'synchronization')
            ->assertJsonPath('items.0.status', 'succeeded')
            ->assertJsonPath('items.0.affected_records', 4)
            ->assertJsonMissingPath('items.0.details.provider_secret');

        $this->activate($tenant);
        $this->assertSame(1, SyncRun::query()->count());
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
