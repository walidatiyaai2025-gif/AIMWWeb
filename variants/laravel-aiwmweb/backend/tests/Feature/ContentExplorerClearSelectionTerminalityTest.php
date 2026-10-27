<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentExplorerClearSelectionTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-F9015FDB11';

    public function test_source_contract_clears_only_the_current_explorer_selection(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );

        $this->assertStringContainsString('@onclick="ClearSelection"', $source);
        $this->assertStringContainsString('private void ClearSelection() => _selected.Clear();', $source);
        $this->assertStringContainsString('disabled="@_bulkQueuing" @onclick="ClearSelection"', $source);
    }

    public function test_laravel_runtime_binds_the_canonical_clear_action_to_tenant_scoped_local_selection(): void
    {
        $control = (string) file_get_contents(
            resource_path('js/content-explorer-clear-selection-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            'data-canonical-operation={CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID}',
            $control,
        );
        $this->assertStringContainsString('if (!canEdit || selectedCount <= 0) return null;', $control);
        $this->assertStringContainsString('onClick={onClear}', $control);

        $this->assertStringContainsString('ContentExplorerClearSelectionControl', $pages);
        $this->assertStringContainsString(
            'selectedCount={explorerSelectedKeys.size}',
            $pages,
        );
        $this->assertStringContainsString(
            'onClear={() => setExplorerSelectedKeys(new Set())}',
            $pages,
        );
        $this->assertStringContainsString(
            "context.permissions.includes('content.edit')",
            $pages,
        );

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString('tenant', strtolower($control));
        $this->assertStringNotContainsString('siteid', strtolower($control));
        $this->assertStringNotContainsString(
            'AIMW-CONT-',
            str_replace(self::OPERATION_ID, '', $control),
        );
    }

    public function test_foreign_tenant_or_site_explorer_context_fails_closed_before_selection_state_is_available(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'clear-alpha');
        $alphaSite = $this->site($alpha, 'Clear Alpha');

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'clear-beta');
        $betaSite = $this->site($beta, 'Clear Beta');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/clear-alpha/sites/'.$betaSite->id.'/explorer')
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/clear-beta/sites/'.$alphaSite->id.'/explorer')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "clear-selection-{$slug}-{$user->id}"]);

        foreach (['tenant.view', 'sites.view', 'content.edit'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        } finally {
            $context->forget();
        }
    }
}
