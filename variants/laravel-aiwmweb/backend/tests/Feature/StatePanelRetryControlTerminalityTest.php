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

final class StatePanelRetryControlTerminalityTest extends TestCase
{
    // Exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-AE544B4684';

    public function test_source_semantics_are_the_shared_retry_callback_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppStatePanel.razor'),
        );

        $this->assertStringContainsString('RetryRequested.HasDelegate', $source);
        $this->assertStringContainsString('Text="@(Busy ? RetryingText : RetryText)"', $source);
        $this->assertStringContainsString('Disabled="@Busy"', $source);
        $this->assertStringContainsString('OnClick="RetryAsync"', $source);
        $this->assertStringContainsString(
            'private Task RetryAsync(MouseEventArgs _) => Busy ? Task.CompletedTask : RetryRequested.InvokeAsync();',
            $source,
        );
    }

    public function test_laravel_shared_control_matches_callback_only_busy_guarded_semantics(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString('onRetry?: () => void', $components);
        $this->assertStringContainsString('disabled={retryBusy}', $components);
        $this->assertStringContainsString("aria-busy={retryBusy ? 'true' : 'false'}", $components);
        $this->assertStringContainsString('if (!retryBusy) onRetry();', $components);
        $this->assertStringContainsString('onRetry={retry}', $pages);
        $this->assertStringContainsString('retryText={text(commonText.retry)}', $pages);

        $this->assertStringNotContainsString('apiRequest', $this->statePanelSlice($components));
        $this->assertStringNotContainsString('fetch(', $this->statePanelSlice($components));
        $this->assertStringNotContainsString('POST', $this->statePanelSlice($components));
        $this->assertStringNotContainsString('PATCH', $this->statePanelSlice($components));
        $this->assertStringNotContainsString('DELETE', $this->statePanelSlice($components));
    }

    public function test_foreign_tenant_route_still_returns_404_and_retry_control_has_no_identity_override_surface(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $this->membership($user, 'alpha-state-retry', ['tenant.view', 'sites.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha-state-retry/sites')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/foreign-state-retry/sites')
            ->assertNotFound();

        $components = $this->statePanelSlice((string) file_get_contents(resource_path('js/components.tsx')));
        $this->assertStringNotContainsString('tenant=', $components);
        $this->assertStringNotContainsString('tenantSlug', $components);
        $this->assertStringNotContainsString('user_id', $components);
        $this->assertStringNotContainsString('membership_id', $components);
    }

    public function test_tenant_scoped_retry_consumer_remains_permission_guarded(): void
    {
        $this->withoutVite();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-state-retry', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-state-retry/sites')
            ->assertForbidden();
    }

    private function statePanelSlice(string $components): string
    {
        $start = strpos($components, 'export const STATE_PANEL_RETRY_OPERATION_ID');
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
            'name' => "state-panel-retry-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
