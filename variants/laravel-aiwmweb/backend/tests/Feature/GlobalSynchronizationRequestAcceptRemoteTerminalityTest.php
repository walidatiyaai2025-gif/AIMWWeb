<?php

namespace Tests\Feature;

use App\Sync\GlobalSynchronizationAcceptRemoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalSynchronizationRequestAcceptRemoteTerminalityTest extends TestCase
{
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
}
