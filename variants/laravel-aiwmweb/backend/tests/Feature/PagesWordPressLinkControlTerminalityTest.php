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

final class PagesWordPressLinkControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-4209A8ADF4';

    public function test_exact_canonical_operation_matches_page_link_control(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/pages', $operation['route_screen']);
        $this->assertSame('@item.Link -> @item.Link', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_pages_read_returns_authoritative_persisted_link_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $item = $this->content($membership, $site, 'page', 71, 'https://alpha.example.test/about');

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/'.$site->id.'/content/page', 'GET'));
        $this->assertSame(ContentApiController::class.'@index', ltrim($route->getActionName(), '\\'));
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/sites/'.$site->id.'/content/page?link=https%3A%2F%2Fattacker.example%2Fx')
            ->assertOk()
            ->assertJsonPath('data.0.id', $item->id)
            ->assertJsonPath('data.0.link', 'https://alpha.example.test/about')
            ->assertJsonMissing(['link' => 'https://attacker.example/x']);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray());
    }

    public function test_workspace_auth_permission_and_cross_tenant_boundaries_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/module/pages')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/module/pages')->assertForbidden();

        $alpha = User::factory()->create();
        $alphaMembership = $this->membership($alpha, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $this->content($alphaMembership, $alphaSite, 'page', 71, 'https://alpha.example.test/about');

        $beta = User::factory()->create();
        $betaMembership = $this->membership($beta, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->content($betaMembership, $betaSite, 'page', 88, 'https://beta.example.test/private');

        $this->actingAs($alpha)->get('/tenants/beta/module/pages')->assertNotFound();
        $this->actingAs($alpha)
            ->getJson('/api/v1/tenants/alpha/sites/'.$betaSite->id.'/content/page')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['link' => 'https://beta.example.test/private']);
    }

    public function test_production_wiring_is_pages_only_safe_external_and_read_only(): void
    {
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/pages-wordpress-link-control.tsx'));
        $service = (string) file_get_contents(app_path('Content/ContentPlatformService.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("route.key === 'pages'", $pages);
        $this->assertStringContainsString('<PagesWordPressLinkControl value={row.link} />', $pages);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringContainsString("'link' => \$row['link'] ?? null", $service);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "page-link-{$slug}-{$user->id}"]);
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
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test', 'status' => 'active']);
        $context->forget();

        return $site;
    }

    private function content(TenantMembership $membership, Site $site, string $type, int $remoteId, string $link): ContentItem
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'type' => $type,
            'remote_id' => $remoteId,
            'title' => 'Persisted page',
            'slug' => 'persisted-page',
            'status' => 'publish',
            'link' => $link,
            'remote_hash' => hash('sha256', $link),
            'synced_at' => now(),
        ]);
        $context->forget();

        return $item;
    }
}
