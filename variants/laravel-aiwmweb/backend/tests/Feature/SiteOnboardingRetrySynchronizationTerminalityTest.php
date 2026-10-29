<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SiteOnboardingRetrySynchronizationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-AE16CF49C6';

    public function test_retry_control_uses_the_existing_tenant_scoped_sync_route_and_no_credential_repost(): void
    {
        $source = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $source);
        $this->assertStringContainsString('canonicalOnboardingRetrySyncEndpoint', $source);
        $this->assertStringContainsString("'Idempotency-Key': nextIdempotencyKey()", $source);
        $this->assertStringContainsString("result.sync?.status === 'failed'", $source);
        $this->assertStringContainsString('body: JSON.stringify({})', $source);
    }

    public function test_foreign_tenant_site_sync_is_not_found_and_cannot_enqueue_work(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.manage']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        app(TenantContext::class)->activate($beta);
        $foreign = Site::query()->create([
            'name' => 'Beta Site', 'url' => 'https://beta.example.test', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'onboarding-foreign-retry')
            ->postJson("/api/tenants/alpha/sites/{$foreign->id}/sync", [])
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertNotSame($alpha->id, $beta->id);
    }

    public function test_guest_and_read_only_member_cannot_retry_or_enqueue_work(): void
    {
        Queue::fake();
        $this->postJson('/api/tenants/alpha/sites/1/sync', [])
            ->assertUnauthorized();

        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha', ['tenant.view']);
        app(TenantContext::class)->activate($alpha);
        $site = Site::query()->create([
            'name' => 'Alpha Site', 'url' => 'https://alpha.example.test', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($reader)
            ->withHeader('Idempotency-Key', 'onboarding-reader-retry')
            ->postJson("/api/tenants/alpha/sites/{$site->id}/sync", [])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_retry_replays_once_and_starts_a_new_run_after_failure(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.manage']);
        app(TenantContext::class)->activate($alpha);
        $site = Site::query()->create([
            'name' => 'Alpha Site', 'url' => 'https://alpha.example.test', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $endpoint = "/api/tenants/alpha/sites/{$site->id}/sync";
        $first = $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'onboarding-first-run')
            ->postJson($endpoint, [])
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');
        $firstRunId = (int) $first->json('id');

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'onboarding-first-run')
            ->postJson($endpoint, [])
            ->assertOk()
            ->assertJsonPath('id', $firstRunId)
            ->assertJsonPath('idempotent_replay', true);
        Queue::assertPushed(SyncSiteJob::class, 1);

        SyncRun::query()->withoutGlobalScopes()->whereKey($firstRunId)->update([
            'status' => 'failed',
            'failure' => 'Remote synchronization failed.',
            'completed_at' => now(),
        ]);

        $retry = $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'onboarding-new-retry')
            ->postJson($endpoint, [])
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');
        $retryRunId = (int) $retry->json('id');

        $this->assertNotSame($firstRunId, $retryRunId);
        Queue::assertPushed(SyncSiteJob::class, 2);

        $this->actingAs($manager)
            ->getJson("/api/tenants/alpha/sync-runs/{$retryRunId}")
            ->assertOk()
            ->assertJsonPath('id', $retryRunId)
            ->assertJsonPath('status', 'queued');
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "onboarding-retry-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
