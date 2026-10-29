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

final class AppSearchBoxTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-1778E3F6B5';

    public function test_source_contract_is_controlled_search_clear_busy_and_disabled_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppSearchBox.razor'),
        );

        $this->assertStringContainsString('type="search"', $source);
        $this->assertStringContainsString('@oninput="HandleInput"', $source);
        $this->assertStringContainsString('disabled="@Disabled"', $source);
        $this->assertStringContainsString('role="status" aria-live="polite"', $source);
        $this->assertStringContainsString('!string.IsNullOrWhiteSpace(Value) && ShowClearButton', $source);
        $this->assertStringContainsString('@onclick="ClearAsync"', $source);
        $this->assertStringContainsString('await ValueChanged.InvokeAsync(Value);', $source);
    }

    public function test_laravel_control_and_comments_consumer_match_the_source_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/app-search-box.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('type="search"', $control);
        $this->assertStringContainsString('disabled={disabled}', $control);
        $this->assertStringContainsString('role="status" aria-live="polite"', $control);
        $this->assertStringContainsString("value.trim() !== '' && showClearButton", $control);
        $this->assertStringContainsString("onClick={() => onValueChange('')}", $control);
        $this->assertStringContainsString('<AppSearchBox', $consumer);
        $this->assertStringContainsString("route.key === 'comments'", $consumer);
        $this->assertStringContainsString('busy={query.isFetching}', $consumer);
        $this->assertStringContainsString("if (value === '' && search !== '')", $consumer);
        $this->assertStringContainsString("setSearch('');", $consumer);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_comments_search_is_authoritative_permission_guarded_and_tenant_isolated(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited-searchbox', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Searchbox');

        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/limited-searchbox/sites/{$limitedSite->id}/comments?search=needle")
            ->assertForbidden();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-searchbox', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Searchbox');

        $this->activate($membership);
        Comment::query()->create([
            'site_id' => $site->id,
            'remote_id' => 9101,
            'content_remote_id' => 501,
            'author_name' => 'Needle Author',
            'author_email' => 'needle@example.test',
            'body' => 'Authoritative needle comment',
            'status' => 'approved',
            'remote_created_at' => now(),
            'synced_at' => now(),
        ]);
        Comment::query()->create([
            'site_id' => $site->id,
            'remote_id' => 9102,
            'content_remote_id' => 502,
            'author_name' => 'Other Author',
            'author_email' => 'other@example.test',
            'body' => 'Unrelated',
            'status' => 'approved',
            'remote_created_at' => now()->subMinute(),
            'synced_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha-searchbox/sites/{$site->id}/comments?search=needle&page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.remote_id', 9101);

        $foreignUser = User::factory()->create();
        $foreign = $this->membership($foreignUser, 'foreign-searchbox', ['tenant.view', 'content.view']);
        $foreignSite = $this->site($foreign, 'Foreign Searchbox');

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/foreign-searchbox/sites/{$foreignSite->id}/comments?search=needle")
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "app-search-box-{$slug}-{$user->id}"]);

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
