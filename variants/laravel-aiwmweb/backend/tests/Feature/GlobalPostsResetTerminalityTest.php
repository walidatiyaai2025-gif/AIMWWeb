<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalPostsResetTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_reset_is_bound_to_real_frontend_and_tenant_read_api(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPostsExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/global-posts-confirm-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/global-posts-reset-control.ts'));

        $this->assertStringContainsString('private async Task ResetAsync()', $source);
        $this->assertStringContainsString('_pageSize = 25;', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);
        $this->assertStringContainsString('AIMW-SYNC-1A20057999', $control);
        $this->assertStringContainsString('GLOBAL_POSTS_RESET_OPERATION_ID', $widget);
        $this->assertStringContainsString('queryClient.invalidateQueries', $widget);
        $this->assertStringContainsString('globalPostsFilterQuery(filters)', $widget);
    }

    public function test_read_api_rejects_guests_foreign_tenants_and_unauthorized_members(): void
    {
        $this->getJson('/api/v1/tenants/alpha/global-posts?status=all&per_page=25&page=1')->assertUnauthorized();

        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'alpha-no-content-read']);
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)->getJson('/api/v1/tenants/alpha/global-posts?status=all&per_page=25&page=1')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/tenants/beta/global-posts?status=all&per_page=25&page=1')->assertNotFound();

        app(TenantContext::class)->activate($tenant);
        $permission = Permission::query()->firstOrCreate(['name' => 'content.view']);
        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)->getJson('/api/v1/tenants/alpha/global-posts?status=all&per_page=25&page=1')
            ->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($user)->getJson('/api/v1/tenants/alpha/global-posts?status=nonexistent')
            ->assertUnprocessable();
    }
}
