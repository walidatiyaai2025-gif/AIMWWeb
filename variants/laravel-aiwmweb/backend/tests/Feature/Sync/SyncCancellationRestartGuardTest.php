<?php

namespace Tests\Feature\Sync;

use App\Jobs\ProcessSyncRunJob;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Sync\SyncCancellationRequested;
use App\Sync\SyncCancellationService;
use App\Sync\SyncRuntimeService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncCancellationRestartGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_requested_run_blocks_a_new_sync_until_cancellation_is_finalized(): void
    {
        Queue::fake();
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create([
            'name' => 'Alpha Site',
            'url' => 'https://alpha.example.test',
            'status' => 'active',
        ]);
        $run = SyncRun::query()->create([
            'site_id' => $site->id,
            'state' => 'running',
            'mode' => 'full',
            'trigger' => 'manual',
            'resources' => ['posts'],
            'lease_token' => (string) Str::uuid(),
            'started_at' => now(),
        ]);

        $requested = app(SyncCancellationService::class)->requestForSite($site->id);
        $this->assertSame($run->id, $requested?->id);
        $this->assertSame('cancel_requested', $requested?->state);

        try {
            app(SyncRuntimeService::class)->start($tenant->id, $site->id, true, ['posts']);
            $this->fail('A new synchronization must not start while the previous run is cancel_requested.');
        } catch (SyncCancellationRequested $exception) {
            $this->assertSame($run->id, $exception->syncRunId);
        }

        $this->assertSame(1, SyncRun::withoutGlobalScopes()->where('site_id', $site->id)->count());
        Queue::assertNotPushed(ProcessSyncRunJob::class);
        app(TenantContext::class)->forget();
    }
}
