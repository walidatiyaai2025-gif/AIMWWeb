<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use App\Models\MediaItem;
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

final class ContentExplorerMediaSourceUrlControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B44DA81C28';

    public function test_exact_canonical_operation_matches_content_explorer_media_source_url(): void
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
        $this->assertSame('@item.SourceUrl -> @item.SourceUrl', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_media_read_returns_authoritative_persisted_source_url_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Media');
        $media = $this->media(
            $membership,
            $site,
            71,
            'https://alpha.example.test/wp-content/uploads/hero.jpg',
        );

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/'.$site->id.'/media', 'GET'),
        );
        $this->assertSame(ContentApiController::class.'@media', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = MediaItem::withoutGlobalScopes()->findOrFail($media->id)->toArray();

        $this->actingAs($user)
            ->getJson(
                '/api/v1/tenants/alpha/sites/'.$site->id
                .'/media?source_url=https%3A%2F%2Fattacker.example%2Foverride.jpg',
            )
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath(
                'data.0.source_url',
                'https://alpha.example.test/wp-content/uploads/hero.jpg',
            )
            ->assertJsonMissing([
                'source_url' => 'https://attacker.example/override.jpg',
            ]);

        $this->assertSame(
            $before,
            MediaItem::withoutGlobalScopes()->findOrFail($media->id)->toArray(),
        );
    }

    public function test_permission_and_cross_tenant_media_reads_fail_closed(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Media');
        $this->media(
            $limitedMembership,
            $limitedSite,
            41,
            'https://limited.example.test/private.jpg',
        );

        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/sites/'.$limitedSite->id.'/media')
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $alphaMedia = $this->media(
            $alpha,
            $alphaSite,
            71,
            'https://alpha.example.test/alpha.jpg',
        );

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta Site');
        $this->media(
            $beta,
            $betaSite,
            88,
            'https://beta.example.test/private.jpg',
        );

        $this->actingAs($owner)
            ->getJson('/api/v1/tenants/alpha/sites/'.$alphaSite->id.'/media')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alphaMedia->id)
            ->assertJsonMissing(['source_url' => 'https://beta.example.test/private.jpg']);

        $foreign = $this->actingAs($owner)
            ->getJson('/api/v1/tenants/alpha/sites/'.$betaSite->id.'/media');

        $this->assertContains($foreign->getStatusCode(), [200, 404]);
        if ($foreign->getStatusCode() === 200) {
            $foreign
                ->assertJsonCount(0, 'data')
                ->assertJsonMissing(['source_url' => 'https://beta.example.test/private.jpg']);
        }
    }

    public function test_production_wiring_adapts_source_media_link_to_canonical_media_workspace(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(
            resource_path('js/content-explorer-media-source-url-control.tsx'),
        );
        $mediaTab = (string) file_get_contents(
            resource_path('js/content-explorer-media-tab-control.tsx'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/ContentApiController.php'),
        );

        $this->assertStringContainsString(
            'href="@item.SourceUrl" target="_blank" rel="noopener"',
            $source,
        );
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            '<ContentExplorerMediaSourceUrlControl value={row.source_url} />',
            $pages,
        );
        $this->assertStringContainsString("route.key === 'media'", $pages);
        $this->assertStringContainsString('ContentExplorerMediaTabControl', $mediaTab);
        $this->assertStringContainsString('context.api.media', $mediaTab);
        $this->assertStringContainsString(
            "MediaItem::query()->where('site_id', \$site)",
            $controller,
        );
        $this->assertStringContainsString("authorize('content.view')", $controller);
        $this->assertStringContainsString(
            "parsed.protocol !== 'http:' && parsed.protocol !== 'https:'",
            $control,
        );
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
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
        $role = Role::query()->create(['name' => "media-source-{$slug}-{$user->id}"]);

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

    private function media(
        TenantMembership $membership,
        Site $site,
        int $remoteId,
        string $sourceUrl,
    ): MediaItem {
        return $this->inTenant($membership, fn () => MediaItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'title' => 'Persisted media '.$remoteId,
            'slug' => 'persisted-media-'.$remoteId,
            'mime_type' => 'image/jpeg',
            'media_type' => 'image',
            'source_url' => $sourceUrl,
            'remote_hash' => hash('sha256', $sourceUrl),
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
