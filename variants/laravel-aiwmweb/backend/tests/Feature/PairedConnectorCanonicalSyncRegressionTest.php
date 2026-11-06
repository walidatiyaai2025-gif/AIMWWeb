<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncRunJob;
use App\Jobs\SyncSiteJob;
use App\Models\Connector;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncedContent;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PairedConnectorCanonicalSyncRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_connector_sync_projects_via_canonical_job_without_erasing_legacy_content(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'WordPress Tenant', 'slug' => 'wordpress']);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'verified-connector-sync']);
        foreach (['tenant.view', 'sites.view', 'sites.manage'] as $permission) {
            $model = Permission::query()->firstOrCreate(['name' => $permission]);
            $role->permissions()->attach($model, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

        $site = Site::query()->create([
            'name' => 'Paired site',
            'url' => 'https://wordpress.example.test',
            'status' => 'active',
            'connection_status' => 'CONNECTED',
        ]);
        Connector::query()->create([
            'site_id' => $site->id,
            'identity' => '12345678-1234-4123-8123-123456789abc',
            'encrypted_secret' => 'protected-fixture',
            'verified_at' => now(),
            'enabled_scopes' => ['health', 'content.read'],
            'capabilities' => ['health', 'content.read'],
        ]);
        SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 7,
            'slug' => 'legacy-preserved',
        ]);
        $legacy = SyncRun::query()->create([
            'site_id' => $site->id,
            'status' => 'succeeded',
            'state' => 'queued',
            'completed_at' => now(),
        ]);
        $context->forget();

        $url = "/api/tenants/wordpress/sites/{$site->id}/sync";
        $requestKey = 'verified-connector-sync-regression';

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $requestKey)
            ->postJson($url)
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');

        $runId = (int) $response->json('id');
        $this->assertNotSame($legacy->id, $runId);
        Queue::assertPushed(ProcessSyncRunJob::class, 1);
        Queue::assertNotPushed(SyncSiteJob::class);

        $context->activate($tenant, $membership);
        $run = SyncRun::query()->findOrFail($runId);
        $this->assertSame('full', $run->mode);
        $this->assertSame('site-sync-button', $run->trigger);
        $this->assertSame(['posts', 'pages', 'media', 'categories', 'tags', 'comments'], $run->resources);
        $this->assertSame('queued', $run->state);
        $this->assertSame(1, SyncedContent::query()->where('site_id', $site->id)->count());
        $context->forget();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $requestKey)
            ->postJson($url)
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        Queue::assertPushed(ProcessSyncRunJob::class, 1);

        $context->activate($tenant, $membership);
        SyncRun::query()->whereKey($runId)->update([
            'state' => 'completed',
            'created_count' => 2,
            'completed_at' => now(),
        ]);
        $context->forget();

        $this->actingAs($user)
            ->getJson("/api/tenants/wordpress/sync-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('processed', 2);

        $this->actingAs($user)
            ->getJson("/api/tenants/wordpress/sites/{$site->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('latest_run.status', 'succeeded')
            ->assertJsonPath('latest_run.processed', 2)
            ->assertJsonPath('cached.total', 1);
    }
}
