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

final class ContentExplorerWordPressLinkControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-16D4AD8DC8';

    public function test_exact_canonical_operation_matches_content_explorer_item_link(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/explorer', $operation['route_screen']);
        $this->assertSame('@item.Link -> @item.Link', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_explorer_read_returns_authoritative_persisted_link_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Explorer');
        $item = $this->content($membership, $site, 71, 'https://alpha.example.test/post-71');

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/'.$site->id.'/explorer', 'GET'),
        );
        $this->assertSame(ContentExplorerReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/sites/'.$site->id.'/explorer?link=https%3A%2F%2Fattacker.example%2Fx')
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.link', 'https://alpha.example.test/post-71')
            ->assertJsonMissing(['link' => 'https://attacker.example/x']);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray());
    }

    public function test_permission_foreign_site_and_foreign_tenant_boundaries_fail_closed(): void
    {
        $this->withoutVite();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Explorer');

        $this->actingAs($limited)
            ->get('/tenants/limited/explorer?site='.$limitedSite->id)
            ->assertForbidden();
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/sites/'.$limitedSite->id.'/explorer')
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $this->content($alpha, $alphaSite, 71, 'https://alpha.example.test/post-71');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');
        $this->content($beta, $betaSite, 88, 'https://beta.example.test/private-post');

        $this->actingAs($owner)
            ->get('/tenants/alpha/explorer?site='.$alphaSite->id)
            ->assertOk();
        $this->actingAs($owner)
            ->get('/tenants/alpha/explorer?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($owner)
            ->getJson('/api/v1/tenants/alpha/sites/'.$betaSite->id.'/explorer')
            ->assertNotFound();
        $this->actingAs($owner)
            ->get('/tenants/beta/explorer?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_production_wiring_is_explorer_only_safe_external_and_read_only(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-explorer-wordpress-link-control.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentExplorerReadController.php'));

        $this->assertStringContainsString('href="@item.Link" target="_blank" rel="noopener"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("route.key === 'explorer'", $pages);
        $this->assertStringContainsString('<ContentExplorerWordPressLinkControl value={row.link} />', $pages);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringContainsString("'link' => \$item->link", $controller);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('AIMW-CONT-37B4B4BEC4', $control);
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
        $role = Role::query()->create(['name' => "explorer-link-{$slug}-{$user->id}"]);

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
            'title' => 'Persisted explorer item',
            'slug' => 'persisted-explorer-item',
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
