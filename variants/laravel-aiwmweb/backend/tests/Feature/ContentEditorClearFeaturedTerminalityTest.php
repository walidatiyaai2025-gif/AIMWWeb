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

final class ContentEditorClearFeaturedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-A6867078EA';

    public function test_source_and_laravel_bind_exact_clear_featured_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('@onclick="ClearFeatured"', $source);
        $this->assertStringContainsString('private void ClearFeatured()', $source);
        $this->assertStringContainsString('_model.FeaturedMediaId = 0', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('clearContentEditorFeaturedMedia', $control);
        $this->assertStringContainsString('featured_media: 0', $control);
        $this->assertStringContainsString('ContentEditorClearFeaturedControl', $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_CLEAR_FEATURED_OPERATION_ID}', $control);
    }

    public function test_clear_featured_is_local_editor_state_only_and_adds_no_remote_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function clearContentEditorFeaturedMedia');
        $end = strpos($control, 'export type ContentEditorApprovalResponse', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('success', strtolower($slice));
    }
    public function test_clear_featured_editor_rejects_foreign_tenant_workspace(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Clear Featured Alpha', 'slug' => 'clear-featured-alpha']);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "clear-featured-alpha-{$user->id}"]);
        foreach (['tenant.view', 'sites.view', 'content.view', 'content.edit'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        Tenant::query()->create(['name' => 'Clear Featured Foreign', 'slug' => 'clear-featured-foreign']);

        $this->withoutVite();
        $this->actingAs($user)
            ->get('/tenants/clear-featured-foreign/sites/1/content/posts/10/edit')
            ->assertNotFound();
    }
}
