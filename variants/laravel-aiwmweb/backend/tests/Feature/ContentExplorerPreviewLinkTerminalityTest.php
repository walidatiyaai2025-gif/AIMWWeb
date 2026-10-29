<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentExplorerReadController;
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

final class ContentExplorerPreviewLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-37B4B4BEC4';

    public function test_source_contract_is_the_preview_external_link(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));

        $this->assertStringContainsString('href="@_preview.Link" target="_blank" rel="noopener"', $source);
        $this->assertStringContainsString('Open on site', $source);
        $this->assertStringContainsString('CopyLinkAsync(_preview.Link)', $source);
    }

    public function test_preview_link_is_returned_only_from_authoritative_tenant_site_read(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-preview', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Preview');
        $item = $this->content($membership, $site, 71, 'https://alpha.example.test/post-71');

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha-preview/sites/'.$site->id.'/explorer', 'GET'),
        );
        $this->assertSame(ContentExplorerReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-preview/sites/'.$site->id.'/explorer?link=https%3A%2F%2Fattacker.example%2Fx')
            ->assertOk()
            ->assertJsonPath('data.0.wordpress_id', 71)
            ->assertJsonPath('data.0.link', 'https://alpha.example.test/post-71')
            ->assertJsonMissing(['link' => 'https://attacker.example/x']);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray());
    }

    public function test_preview_runtime_wiring_is_safe_read_only_and_operation_specific(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $panel = (string) file_get_contents(resource_path('js/content-explorer-preview-panel.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentExplorerReadController.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $panel);
        $this->assertStringContainsString('<ContentExplorerPreviewPanel', $pages);
        $this->assertStringContainsString('setExplorerPreview(row)', $pages);
        $this->assertStringContainsString('safeContentExplorerExternalUrl(row.link)', $panel);
        $this->assertStringContainsString('target="_blank"', $panel);
        $this->assertStringContainsString('rel="noopener noreferrer"', $panel);
        $this->assertStringContainsString("'link' => \$item->link", $controller);
        $this->assertStringNotContainsString('apiRequest', $panel);
        $this->assertStringNotContainsString('fetch(', $panel);
        $this->assertStringNotContainsString('AIMW-CONT-93B6E02ACF', $panel);
        $this->assertStringNotContainsString('AIMW-CONT-9BA2C255AF', $panel);
    }

    public function test_missing_permission_foreign_site_and_foreign_tenant_fail_closed(): void
    {
        $this->withoutVite();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited-preview', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Preview');

        $this->actingAs($limited)
            ->get('/tenants/limited-preview/explorer?site='.$limitedSite->id)
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha-preview-iso', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Isolated');
        $this->content($alpha, $alphaSite, 71, 'https://alpha.example.test/post-71');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-preview-iso', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Isolated');
        $this->content($beta, $betaSite, 88, 'https://beta.example.test/private');

        $this->actingAs($owner)
            ->getJson('/api/v1/tenants/alpha-preview-iso/sites/'.$betaSite->id.'/explorer')
            ->assertNotFound();
        $this->actingAs($owner)
            ->get('/tenants/beta-preview-iso/explorer?site='.$betaSite->id)
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "preview-link-{$slug}-{$user->id}"]);

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
        return $this->inTenant($membership, fn () => Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
            'last_sync_at' => now(),
        ]));
    }

    private function content(TenantMembership $membership, Site $site, int $remoteId, string $link): ContentItem
    {
        return $this->inTenant($membership, fn () => ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'title' => 'Preview item',
            'slug' => 'preview-item',
            'status' => 'publish',
            'link' => $link,
            'remote_hash' => hash('sha256', $link),
            'remote_modified_at' => now(),
            'synced_at' => now(),
        ]));
    }

    private function inTenant(TenantMembership $membership, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
