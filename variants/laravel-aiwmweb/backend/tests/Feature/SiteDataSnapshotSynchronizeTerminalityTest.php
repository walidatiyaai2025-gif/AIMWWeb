<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDataSnapshotController;
use App\Jobs\SyncSiteJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteDataSnapshotSynchronizeTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-2D6F2BC88E';

    public function test_source_and_laravel_routes_bind_exact_snapshot_sync_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteDataSnapshot.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/snapshot"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/offline-data"', $source);
        $this->assertStringContainsString('OnClick="SynchronizeAsync"', $source);
        $this->assertStringContainsString('var result = await SyncService.SynchronizeAsync(Id);', $source);
        $this->assertStringContainsString('await LoadAsync();', $source);

        $snapshot = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/snapshot', 'GET'));
        $this->assertSame(SiteDataSnapshotController::class, ltrim($snapshot->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $snapshot->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $snapshot->gatherMiddleware());
        $this->assertContains('tenant.context', $snapshot->gatherMiddleware());

        $sync = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/sync', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\DemoController@sync', ltrim($sync->getActionName(), '\\'));
        $this->assertContains('web', $sync->gatherMiddleware());
        $this->assertContains('auth', $sync->gatherMiddleware());
        $this->assertContains('tenant.context', $sync->gatherMiddleware());
    }

    public function test_guest_permission_and_foreign_site_fail_closed_without_dispatch(): void
    {
        Queue::fake();

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertUnauthorized();

        $this->actingAs($alphaUser)
            ->withHeader('Idempotency-Key', 'snapshot-alpha-denied')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertForbidden();

        $alphaManager = User::factory()->create();
        $this->membershipExisting($alphaManager, $alpha, ['tenant.view', 'sites.view', 'sites.manage'], 'alpha-manager');
        $this->actingAs($alphaManager)
            ->withHeader('Idempotency-Key', 'snapshot-foreign-site')
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->activate($alpha);
        $this->assertSame(0, SyncRun::query()->count());
        app(TenantContext::class)->forget();
    }

    public function test_sync_command_is_idempotent_and_snapshot_reread_is_authoritative(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($tenant);
        $site = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $key = 'snapshot-sync-11111111-1111-4111-8111-111111111111';
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

        $this->activate($tenant);
        $site = Site::query()->findOrFail($site->id);
        $site->update(['last_sync_at' => now()]);
        SyncRun::query()->findOrFail($runId)->update([
            'status' => 'succeeded',
            'processed' => 4,
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/alpha/sites/{$site->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('site.id', $site->id)
            ->assertJsonPath('site.name', 'Alpha Site')
            ->assertJsonPath('cached.total', 0)
            ->assertJsonPath('latest_run.id', $runId)
            ->assertJsonPath('latest_run.status', 'succeeded')
            ->assertJsonPath('latest_run.processed', 4)
            ->assertJsonMissingPath('site.url')
            ->assertJsonMissingPath('provider_secret');
    }

    public function test_same_idempotency_key_cannot_be_rebound_to_another_site(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($tenant);
        $firstSite = Site::query()->create(['name' => 'One', 'url' => 'https://one.test', 'status' => 'active']);
        $secondSite = Site::query()->create(['name' => 'Two', 'url' => 'https://two.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $key = 'snapshot-sync-conflict-11111111';
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$firstSite->id}/sync")
            ->assertAccepted();

        $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->postJson("/api/tenants/alpha/sites/{$secondSite->id}/sync")
            ->assertConflict();

        Queue::assertPushed(SyncSiteJob::class, 1);
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
