<?php

namespace Tests\Feature\Sync;

use App\Http\Controllers\SiteSyncCancellationController;
use App\Jobs\ProcessSyncRunJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncBatch;
use App\Models\SyncEvent;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sync\SyncCancellationRequested;
use App\Sync\SyncCancellationService;
use App\Sync\SyncRuntimeService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncCancellationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-54BB64BB13';

    public function test_exact_canonical_operation_is_the_pending_cancel_synchronization_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteDetails.razor', $operation['current_source']);
        $this->assertStringContainsString('CancelSynchronization', (string) $operation['visible_control']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_cancel_routes_are_explicit_authenticated_tenant_scoped_runtime_contracts(): void
    {
        $active = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/1/sync/active', 'GET'));
        $cancel = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/1/sync/cancel', 'POST'));

        $this->assertSame(
            SiteSyncCancellationController::class.'@active',
            ltrim($active->getActionName(), '\\'),
        );
        $this->assertSame(
            SiteSyncCancellationController::class.'@cancel',
            ltrim($cancel->getActionName(), '\\'),
        );
        $this->assertContains('auth', $active->gatherMiddleware());
        $this->assertContains('tenant.context', $active->gatherMiddleware());
        $this->assertContains('auth', $cancel->gatherMiddleware());
        $this->assertContains('tenant.context', $cancel->gatherMiddleware());
        $this->assertSame(self::OPERATION_ID, $cancel->defaults['canonical_operation_id'] ?? null);
    }

    public function test_cancel_requires_content_edit_and_fails_closed_for_foreign_sites(): void
    {
        $authorized = User::factory()->create();
        $authorizedMembership = $this->membership($authorized, 'alpha', ['content.edit']);
        $alphaSite = $this->site($authorizedMembership, 'Alpha');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', []);
        $limitedSite = $this->site($limitedMembership, 'Limited');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['content.edit']);
        $betaSite = $this->site($betaMembership, 'Beta');

        $this->actingAs($limited)
            ->postJson("/api/v1/tenants/limited/sites/{$limitedSite->id}/sync/cancel")
            ->assertForbidden();

        $this->actingAs($authorized)
            ->postJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/sync/cancel")
            ->assertNotFound();

        $this->actingAs($authorized)
            ->postJson("/api/v1/tenants/beta/sites/{$alphaSite->id}/sync/cancel")
            ->assertNotFound();
    }

    public function test_no_active_sync_returns_conflict_without_fabricating_success(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.edit']);
        $site = $this->site($membership, 'Alpha');

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/sync/active")
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('run', null);

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/alpha/sites/{$site->id}/sync/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'No active synchronization is available to cancel.');

        $this->assertSame(0, SyncRun::withoutGlobalScopes()->count());
        $this->assertSame(0, SyncEvent::withoutGlobalScopes()->where('event_type', 'SyncCancelled')->count());
    }

    public function test_running_sync_moves_through_cancel_requested_and_worker_finalizes_cancelled(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.edit']);
        $site = $this->site($membership, 'Alpha');
        $run = $this->run($membership, $site, 'running');
        $batch = $this->batch($membership, $site, $run, 'running');

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/alpha/sites/{$site->id}/sync/cancel")
            ->assertAccepted()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.state', 'cancel_requested');

        $this->assertSame('cancel_requested', SyncRun::withoutGlobalScopes()->findOrFail($run->id)->state);
        $this->assertSame('running', SyncBatch::withoutGlobalScopes()->findOrFail($batch->id)->state);

        $this->activate($membership);
        $job = new ProcessSyncRunJob($membership->tenant_id, $run->id);
        $job->handle(app(SyncRuntimeService::class), app(SyncCancellationService::class));

        $this->assertSame('cancelled', SyncRun::withoutGlobalScopes()->findOrFail($run->id)->state);
        $this->assertSame('cancelled', SyncBatch::withoutGlobalScopes()->findOrFail($batch->id)->state);
        $this->assertSame(1, SyncEvent::withoutGlobalScopes()
            ->where('sync_run_id', $run->id)
            ->where('event_type', 'SyncCancelled')
            ->count());
        $this->assertFalse(SyncEvent::withoutGlobalScopes()
            ->where('sync_run_id', $run->id)
            ->where('event_type', 'SyncCompleted')
            ->exists());
        app(TenantContext::class)->forget();
    }

    public function test_stale_runtime_model_cannot_resurrect_a_requested_cancellation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.edit']);
        $site = $this->site($membership, 'Alpha');
        $run = $this->run($membership, $site, 'running');

        $this->activate($membership);
        $stale = SyncRun::query()->findOrFail($run->id);
        $requested = app(SyncCancellationService::class)->requestForSite($site->id);
        $this->assertSame('cancel_requested', $requested?->state);

        try {
            $stale->forceFill([
                'state' => 'completed',
                'completed_at' => now(),
            ])->save();
            $this->fail('A stale sync runtime save must not overwrite cancel_requested.');
        } catch (SyncCancellationRequested $exception) {
            $this->assertSame($run->id, $exception->syncRunId);
        }

        $this->assertSame('cancel_requested', SyncRun::withoutGlobalScopes()->findOrFail($run->id)->state);
        $this->assertFalse(SyncEvent::withoutGlobalScopes()
            ->where('sync_run_id', $run->id)
            ->where('event_type', 'SyncCompleted')
            ->exists());
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "sync-cancel-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $this->activate($membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower($name).'.example.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function run(TenantMembership $membership, Site $site, string $state): SyncRun
    {
        $this->activate($membership);
        $run = SyncRun::query()->create([
            'site_id' => $site->id,
            'state' => $state,
            'mode' => 'full',
            'trigger' => 'manual',
            'resources' => ['posts'],
            'lease_token' => (string) Str::uuid(),
            'requested_at' => now(),
            'started_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $run;
    }

    private function batch(TenantMembership $membership, Site $site, SyncRun $run, string $state): SyncBatch
    {
        $this->activate($membership);
        $batch = SyncBatch::query()->create([
            'site_id' => $site->id,
            'sync_run_id' => $run->id,
            'resource' => 'posts',
            'page' => 1,
            'state' => $state,
            'started_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $batch;
    }

    private function activate(TenantMembership $membership): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
    }
}
