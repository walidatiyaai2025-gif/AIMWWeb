<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerPreviewEditorLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9BA2C255AF';

    public function test_source_contract_is_preview_edit_or_view_route(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );

        $this->assertStringContainsString(
            'href="@($"/sites/{Id}/content/{_preview.ContentType}/{_preview.WordPressId}/edit")"',
            $source,
        );
        $this->assertStringContainsString('CanEditContent ? L["Edit"]', $source);
        $this->assertStringContainsString('(L.IsArabic ? "عرض" : "View")', $source);
    }

    public function test_laravel_preview_editor_link_targets_real_tenant_scoped_editor_routes(): void
    {
        $webRoute = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/7/content/post/101/edit', 'GET'),
        );
        $this->assertSame('canonical.site.content-editor', $webRoute->getName());
        $this->assertSame('content.view', $webRoute->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $webRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $webRoute->gatherMiddleware());

        $apiRoute = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/post/101/editor', 'GET'),
        );
        $this->assertSame(ContentApiController::class.'@editor', ltrim($apiRoute->getActionName(), '\\'));
        $this->assertSame('api.v1.content.editor.show', $apiRoute->getName());
        $this->assertContains('auth', $apiRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $apiRoute->gatherMiddleware());

        $control = (string) file_get_contents(resource_path('js/content-explorer-preview-editor-link-control.tsx'));
        $panel = (string) file_get_contents(resource_path('js/content-explorer-preview-panel.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('contentExplorerPreviewEditorHref', $control);
        $this->assertStringContainsString("context.permissions.includes('content.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('content.edit')", $control);
        $this->assertStringContainsString('<ContentExplorerPreviewEditorLinkControl context={context} row={row} />', $panel);
        $this->assertStringContainsString('context={context}', $pages);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('AIMW-CONT-37B4B4BEC4', $control);
        $this->assertStringNotContainsString('AIMW-CONT-93B6E02ACF', $control);
    }

    public function test_editor_read_is_authoritative_read_only_and_tenant_site_scoped(): void
    {
        $this->withoutVite();

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'preview-editor-alpha', [
            'tenant.view', 'sites.view', 'content.view', 'content.edit',
        ]);
        [$alphaSite, $alphaItem] = $this->siteAndItem($alpha, 101, 'post');

        $before = ContentItem::withoutGlobalScopes()->findOrFail($alphaItem->id)->toArray();

        $this->actingAs($editor)
            ->get("/tenants/preview-editor-alpha/sites/{$alphaSite->id}/content/post/101/edit")
            ->assertOk();

        $this->actingAs($editor)
            ->getJson("/api/v1/tenants/preview-editor-alpha/sites/{$alphaSite->id}/content/post/101/editor")
            ->assertOk()
            ->assertJsonPath('wordpress_id', 101)
            ->assertJsonPath('type', 'post');

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($alphaItem->id)->toArray());

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['tenant.view', 'sites.view', 'content.view'], 'preview-viewer');
        $this->actingAs($viewer)
            ->get("/tenants/preview-editor-alpha/sites/{$alphaSite->id}/content/post/101/edit")
            ->assertOk();
        $this->actingAs($viewer)
            ->getJson("/api/v1/tenants/preview-editor-alpha/sites/{$alphaSite->id}/content/post/101/editor")
            ->assertOk();
    }

    public function test_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $this->withoutVite();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'preview-editor-isolated-alpha', [
            'tenant.view', 'sites.view', 'content.view',
        ]);
        [$alphaSite] = $this->siteAndItem($alpha, 101, 'post');

        $limited = User::factory()->create();
        $this->membershipExisting($limited, $alpha->tenant, ['tenant.view', 'sites.view'], 'preview-limited');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'preview-editor-isolated-beta', [
            'tenant.view', 'sites.view', 'content.view',
        ]);
        [$betaSite] = $this->siteAndItem($beta, 202, 'post');

        $this->actingAs($limited)
            ->get("/tenants/preview-editor-isolated-alpha/sites/{$alphaSite->id}/content/post/101/edit")
            ->assertForbidden();
        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/preview-editor-isolated-alpha/sites/{$alphaSite->id}/content/post/101/editor")
            ->assertForbidden();

        $this->actingAs($owner)
            ->get("/tenants/preview-editor-isolated-alpha/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();
        $this->actingAs($owner)
            ->getJson("/api/v1/tenants/preview-editor-isolated-alpha/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();
        $this->actingAs($owner)
            ->get("/tenants/preview-editor-isolated-beta/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "preview-editor-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function siteAndItem(TenantMembership $membership, int $remoteId, string $type): array
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            $site = Site::query()->create([
                'name' => 'Preview Editor '.$remoteId,
                'url' => "https://preview-editor-{$remoteId}.example.test",
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
            $item = ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'type' => $type,
                'title' => 'Preview editor item',
                'slug' => 'preview-editor-item',
                'status' => 'publish',
                'link' => "https://preview-editor.example.test/{$remoteId}",
                'remote_hash' => hash('sha256', (string) $remoteId),
                'remote_modified_at' => now(),
                'synced_at' => now(),
            ]);

            return [$site, $item];
        } finally {
            $context->forget();
        }
    }
}
