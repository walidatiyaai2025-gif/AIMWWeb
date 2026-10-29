<?php

namespace Tests\Feature;

use App\Models\AiUsageRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AppFilterBarClearControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-F7CE702C7D';

    public function test_source_semantics_are_callback_only_busy_guarded_clear_filters(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppFilterBar.razor'),
        );

        $this->assertStringContainsString('role="search"', $source);
        $this->assertStringContainsString('ActiveFilterCount > 0 && ClearRequested.HasDelegate', $source);
        $this->assertStringContainsString('Disabled="@Busy"', $source);
        $this->assertStringContainsString('OnClick="ClearAsync"', $source);
        $this->assertStringContainsString(
            'private Task ClearAsync(MouseEventArgs _) => Busy ? Task.CompletedTask : ClearRequested.InvokeAsync();',
            $source,
        );
    }

    public function test_laravel_filter_bar_matches_clear_visibility_and_busy_guard_semantics(): void
    {
        $component = (string) file_get_contents(resource_path('js/app-filter-bar.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/ai-usage-load-workspace.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('role="search"', $component);
        $this->assertStringContainsString("const canClear = count > 0 && typeof onClearRequested === 'function';", $component);
        $this->assertStringContainsString('disabled={effectiveBusy}', $component);
        $this->assertStringContainsString('await onClearRequested();', $component);
        $this->assertStringContainsString('data-canonical-operation={APP_FILTER_BAR_CLEAR_OPERATION_ID}', $component);

        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('POST', $component);
        $this->assertStringNotContainsString('PATCH', $component);
        $this->assertStringNotContainsString('DELETE', $component);

        $this->assertStringContainsString('<AppFilterBar', $consumer);
        $this->assertStringContainsString('activeFilterCount={selectedSiteId ? 1 : 0}', $consumer);
        $this->assertStringContainsString('onClearRequested={async () => {', $consumer);
        $this->assertStringContainsString("await load('');", $consumer);
        $this->assertStringContainsString('<AppFilterChip', $consumer);
    }

    public function test_real_ai_usage_consumer_remains_authorization_guarded_and_tenant_isolated(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-filterbar', ['tenant.view']);
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited-filterbar/ai/usage')
            ->assertForbidden();

        $allowed = User::factory()->create();
        $alpha = $this->membership($allowed, 'alpha-filterbar', ['tenant.view', 'ai.viewUsage']);

        $foreignUser = User::factory()->create();
        $beta = $this->membership($foreignUser, 'beta-filterbar', ['tenant.view', 'ai.viewUsage']);
        $foreignSite = $this->site($beta->tenant, 'Foreign filter site');

        $this->actingAs($allowed)
            ->getJson('/api/v1/tenants/alpha-filterbar/ai/usage?site='.$foreignSite->id)
            ->assertNotFound();

        $this->assertNotSame($alpha->tenant_id, $beta->tenant_id);
        $this->assertSame(0, AiUsageRecord::query()->withoutGlobalScopes()->count());
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
        $role = Role::query()->create([
            'name' => "filterbar-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://foreign-filterbar.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
