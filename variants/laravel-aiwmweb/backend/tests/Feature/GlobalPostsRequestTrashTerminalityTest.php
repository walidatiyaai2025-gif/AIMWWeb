<?php

namespace Tests\Feature;

use App\Http\Controllers\GlobalPostsTrashController;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class GlobalPostsRequestTrashTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_only_opens_a_confirmation_and_real_wordpress_mutation_is_guarded_by_confirm(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/global-posts-confirm-trash-widget.tsx'));
        $requestControl = (string) file_get_contents(resource_path('js/global-posts-request-trash-control.ts'));

        $this->assertStringContainsString('private Task RequestTrashAsync()', $source);
        $this->assertStringContainsString('if (_selected.Count > 0) _trashConfirmOpen = true;', $source);
        $this->assertStringContainsString('private async Task ConfirmTrashAsync()', $source);
        $this->assertStringContainsString('TrashService.RunAsync', $source);
        $this->assertStringContainsString('AIMW-SYNC-476CDB769B', $requestControl);
        $this->assertStringContainsString('GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID', $widget);
        $this->assertStringContainsString('data-request-operation={GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID}', $widget);
        $this->assertStringContainsString('canRequestGlobalPostsTrash(selectedTargets.length, canEdit, busy)', $widget);
        $this->assertStringContainsString('onClick={() => mutation.mutate(selectedTargets)}', $widget);
        $this->assertStringContainsString('await query.refetch()', $widget);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/global-posts/trash', 'POST'));
        $this->assertSame(GlobalPostsTrashController::class.'@trash', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_confirmation_remote_endpoint_refuses_guest_unprivileged_member_and_cross_tenant_identity(): void
    {
        $payload = ['targets' => [['site_id' => 11, 'wordpress_id' => 101]]];
        $this->postJson('/api/v1/tenants/alpha/global-posts/trash', $payload)->assertUnauthorized();

        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'alpha-unprivileged']);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)->postJson('/api/v1/tenants/alpha/global-posts/trash', $payload)->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/tenants/beta/global-posts/trash', $payload)->assertNotFound();
    }
}
