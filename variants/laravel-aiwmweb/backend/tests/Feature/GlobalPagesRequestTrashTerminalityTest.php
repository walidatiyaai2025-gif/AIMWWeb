<?php

namespace Tests\Feature;

use App\Http\Controllers\GlobalPagesTrashController;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalPagesRequestTrashTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-E3EB27C678';

    public function test_request_trash_is_a_local_confirmation_only_and_confirm_uses_guarded_tenant_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/global-pages-confirm-trash-widget.tsx'));
        $requestControl = (string) file_get_contents(resource_path('js/global-pages-request-trash-control.ts'));

        $this->assertStringContainsString('private Task RequestTrashAsync()', $source);
        $this->assertStringContainsString('if (_selected.Count > 0) _trashConfirmOpen = true;', $source);
        $this->assertStringContainsString('private async Task ConfirmTrashAsync()', $source);
        $this->assertStringContainsString('TrashService.RunAsync', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $requestControl);
        $this->assertStringContainsString('data-request-operation={GLOBAL_PAGES_REQUEST_TRASH_OPERATION_ID}', $widget);
        $this->assertStringContainsString('canRequestGlobalPagesTrash(selectedTargets.length, canEdit, busy)', $widget);
        $this->assertStringContainsString('setConfirmOpen(true)', $widget);
        $this->assertStringContainsString('SyncAppConfirmDialogConfirmControl', $widget);
        $this->assertStringContainsString('mutation.mutateAsync(selectedTargets)', $widget);
        $this->assertStringContainsString('await query.refetch()', $widget);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/global-pages/trash', 'POST'));
        $this->assertSame(GlobalPagesTrashController::class.'@trash', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_remote_confirm_endpoint_rejects_guest_unprivileged_and_foreign_tenant(): void
    {
        $payload = ['targets' => [['site_id' => 11, 'wordpress_id' => 101]]];
        $this->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'alpha-unprivileged']);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)->postJson('/api/v1/tenants/alpha/global-pages/trash', $payload)->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/tenants/beta/global-pages/trash', $payload)->assertNotFound();
    }
}
