<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommentsSearchClickedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-4E3C0156FC';

    public function test_source_and_laravel_bind_exact_search_clicked_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/CommentsManager.razor'),
        );
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="SearchClickedAsync"', $source);
        $this->assertStringContainsString('private Task SearchClickedAsync(MouseEventArgs _) => ReloadFromFirstPageAsync();', $source);
        $this->assertStringContainsString('private async Task ReloadFromFirstPageAsync() { _page = 1; await LoadAsync(); }', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('COMMENTS_SEARCH_OPERATION_ID', $frontend);
        $this->assertStringContainsString("route.key === 'comments' ? COMMENTS_SEARCH_OPERATION_ID : undefined", $frontend);
        $this->assertStringContainsString('setPage(1); setSearch(searchInput);', $frontend);
        $this->assertStringContainsString("queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search, taxonomyFilter))", $frontend);
    }

    public function test_search_reads_only_matching_authoritative_comments_for_the_current_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');

        $this->activate($membership);
        Comment::query()->create([
            'site_id' => $site->id,
            'remote_id' => 101,
            'content_remote_id' => 11,
            'author_name' => 'Needle Author',
            'author_email' => 'needle@example.test',
            'body' => 'Matched body',
            'status' => 'approved',
            'remote_created_at' => now()->subMinute(),
            'synced_at' => now(),
        ]);
        Comment::query()->create([
            'site_id' => $site->id,
            'remote_id' => 102,
            'content_remote_id' => 12,
            'author_name' => 'Other Author',
            'author_email' => 'other@example.test',
            'body' => 'Unrelated body',
            'status' => 'hold',
            'remote_created_at' => now(),
            'synced_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/comments?search=needle&page=1")
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.remote_id', 101)
            ->assertJsonPath('data.0.author_name', 'Needle Author');
    }

    public function test_search_empty_result_and_foreign_tenant_fail_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/comments?search=missing&page=1")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $foreignUser = User::factory()->create();
        $foreign = $this->membership($foreignUser, 'foreign', ['tenant.view', 'content.view']);
        $foreignSite = $this->site($foreign, 'Foreign Site');

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/foreign/sites/{$foreignSite->id}/comments?search=anything&page=1")
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "comments-search-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.str($name)->slug().'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function activate(TenantMembership $membership): void
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
    }
}
