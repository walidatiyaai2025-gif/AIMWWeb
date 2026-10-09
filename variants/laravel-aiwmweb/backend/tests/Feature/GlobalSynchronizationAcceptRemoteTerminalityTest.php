<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncRunJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sync\GlobalSynchronizationAcceptRemoteService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationAcceptRemoteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_contract_and_canonical_route_bind_exact_accept_remote_operation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-accept-remote-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('OnConfirm="AcceptRemoteAsync"', $source);
        $this->assertStringContainsString('SyncService.SynchronizeAsync(_selectedSiteId, forceFullRefresh: true)', $source);
        $this->assertStringContainsString('await LoadSnapshotAsync();', $source);
        $this->assertStringContainsString(GlobalSynchronizationAcceptRemoteService::OPERATION_ID, $frontend);
        $this->assertStringContainsString('GlobalSynchronizationAcceptRemoteControl', $app);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/sync/accept-remote', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\SyncApiController@acceptRemote', ltrim($route->getActionName(), '\\'));
        $this->assertSame(GlobalSynchronizationAcceptRemoteService::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_accept_remote_is_full_idempotent_tenant_scoped_and_dispatches_once(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view', 'content.edit', 'sync.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $key = 'accept-remote-11111111-1111-4111-8111-111111111111';
        $url = "/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/accept-remote";

        $first = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, [])
            ->assertAccepted()
            ->assertJsonPath('operation_id', GlobalSynchronizationAcceptRemoteService::OPERATION_ID)
            ->assertJsonPath('site_id', $alphaSite->id)
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('mode', 'full')
            ->assertJsonPath('trigger', 'accept-remote')
            ->assertJsonPath('idempotent_replay', false);

        $runId = (int) $first->json('id');
        $this->assertGreaterThan(0, $runId);

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', $key)
            ->postJson($url, [])
            ->assertOk()
            ->assertJsonPath('id', $runId)
            ->assertJsonPath('idempotent_replay', true);

        Queue::assertPushed(ProcessSyncRunJob::class, 1);

        $this->activate($alpha);
        $run = SyncRun::query()->findOrFail($runId);
        $this->assertSame('full', $run->mode);
        $this->assertSame('accept-remote', $run->trigger);
        $this->assertSame(
            GlobalSynchronizationAcceptRemoteService::OPERATION_ID,
            data_get($run->metadata, 'canonical_operation_id'),
        );
        app(TenantContext::class)->forget();
    }

    public function test_guest_missing_permission_foreign_site_and_caller_owned_overrides_fail_closed(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view', 'content.edit', 'sync.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha, ['content.view', 'sync.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit', 'sync.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/accept-remote")
            ->assertUnauthorized();

        $this->actingAs($viewer)
            ->withHeader('Idempotency-Key', 'viewer-key')
            ->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/accept-remote")
            ->assertForbidden();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'foreign-site-key')
            ->postJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/sync/accept-remote")
            ->assertNotFound();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'spoof-key')
            ->postJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/sync/accept-remote", [
                'tenant_id' => $beta->id,
                'user_id' => $betaOwner->id,
                'full' => false,
                'resources' => ['posts'],
            ])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $this->activate($tenant);
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
            ]);
        } finally {
            app(TenantContext::class)->forget();
        }
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
