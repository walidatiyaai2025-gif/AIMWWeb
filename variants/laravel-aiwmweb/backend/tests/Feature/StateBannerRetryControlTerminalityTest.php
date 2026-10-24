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

final class StateBannerRetryControlTerminalityTest extends TestCase
{
    // Final exact-head CI retrigger after combined #762 + #994 parity materialization.
    // Exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-8719C812DC';

    public function test_source_semantics_are_the_retained_content_retry_banner(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppStateBanner.razor'),
        );

        $this->assertStringContainsString('data-retains-content="true"', $source);
        $this->assertStringContainsString('RetryRequested.HasDelegate', $source);
        $this->assertStringContainsString('Text="@(Busy ? RetryingText : RetryText)"', $source);
        $this->assertStringContainsString('Disabled="@Busy"', $source);
        $this->assertStringContainsString('OnClick="RetryAsync"', $source);
        $this->assertStringContainsString(
            'private Task RetryAsync(MouseEventArgs _) => Busy ? Task.CompletedTask : RetryRequested.InvokeAsync();',
            $source,
        );
    }

    public function test_laravel_banner_matches_retained_content_and_guarded_retry_semantics(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/ai-usage-load-workspace.tsx'));
        $slice = $this->stateBannerSlice($components);

        $this->assertStringContainsString(self::OPERATION_ID, $slice);
        $this->assertStringContainsString('data-retains-content="true"', $slice);
        $this->assertStringContainsString("normalized === 'cached' || normalized === 'stale'", $slice);
        $this->assertStringContainsString("normalized === 'error' || normalized === 'danger'", $slice);
        $this->assertStringContainsString('disabled={busy}', $slice);
        $this->assertStringContainsString('if (!busy) onRetry();', $slice);
        $this->assertStringContainsString("aria-live={isAssertive ? 'assertive' : 'polite'}", $slice);
        $this->assertStringContainsString('<StateBanner', $consumer);
        $this->assertStringContainsString('kind="cached"', $consumer);
        $this->assertStringContainsString('onRetry={() => void load(selectedSiteId)}', $consumer);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_ai_usage_consumer_is_authorization_guarded_and_foreign_site_fails_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited-banner', ['tenant.view']);
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited-banner/ai/usage')
            ->assertForbidden();

        $allowed = User::factory()->create();
        $alpha = $this->membership($allowed, 'alpha-banner', ['tenant.view', 'ai.viewUsage']);

        $foreignUser = User::factory()->create();
        $beta = $this->membership($foreignUser, 'beta-banner', ['tenant.view', 'ai.viewUsage']);
        $foreignSite = $this->site($beta->tenant, 'Foreign banner site');

        $this->actingAs($allowed)
            ->getJson('/api/v1/tenants/alpha-banner/ai/usage?site='.$foreignSite->id)
            ->assertNotFound();

        $this->assertNotSame($alpha->tenant_id, $beta->tenant_id);
        $this->assertSame(0, AiUsageRecord::query()->withoutGlobalScopes()->count());
    }

    private function stateBannerSlice(string $components): string
    {
        $start = strpos($components, 'export const STATE_BANNER_RETRY_OPERATION_ID');
        $end = strpos($components, 'export function LoadingState', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($components, (int) $start, (int) $end - (int) $start);
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
            'name' => "state-banner-{$slug}-{$user->id}",
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
            'url' => 'https://foreign-banner.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
