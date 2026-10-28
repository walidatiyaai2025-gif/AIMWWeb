<?php

namespace Tests\Feature;

use App\Jobs\ProcessSyncRunJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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

final class GlobalSynchronizationRequestAcceptRemoteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_accept_remote_opens_confirmation_before_authoritative_full_sync(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-accept-remote-control.tsx'));
        $this->assertStringContainsString('OnClick="RequestAcceptRemoteAsync"', $source);
        $this->assertStringContainsString('OnConfirm="AcceptRemoteAsync"', $source);
        $this->assertStringContainsString('SyncService.SynchronizeAsync(_selectedSiteId, forceFullRefresh: true)', $source);
        $this->assertStringContainsString('data-canonical-operation={GLOBAL_SYNCHRONIZATION_REQUEST_ACCEPT_REMOTE_OPERATION_ID}', $frontend);
        $this->assertStringContainsString("GLOBAL_SYNCHRONIZATION_REQUEST_ACCEPT_REMOTE_OPERATION_ID = 'AIMW-SYNC-1B63FBC758'", $frontend);
        $this->assertStringContainsString('setConfirmOpen(true)', $frontend);
        $this->assertStringContainsString('await apiRequest<SyncRunEnvelope>(runEndpoint)', $frontend);
        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/sync/accept-remote', 'POST'));
        $this->assertSame(GlobalSynchronizationAcceptRemoteService::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_request_accept_remote_cannot_dispatch_for_foreign_tenant_site(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        app(TenantContext::class)->activate($alpha);
        $membership = TenantMembership::query()->create(['user_id' => $owner->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'sync-owner-'.$owner->id]);
        foreach (['content.view', 'content.edit', 'sync.view'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $alpha->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $alpha->id]);
        app(TenantContext::class)->forget();

        app(TenantContext::class)->activate($beta);
        $foreignSite = Site::query()->create([
            'name' => 'Foreign WordPress',
            'url' => 'https://foreign.example.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'foreign-request-accept-remote')
            ->postJson("/api/v1/tenants/alpha/sites/{$foreignSite->id}/sync/accept-remote", [])
            ->assertNotFound();

        Queue::assertNotPushed(ProcessSyncRunJob::class);
    }
}
