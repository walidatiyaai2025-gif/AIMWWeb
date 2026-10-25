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

final class ContentExplorerMediaSourceLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B44DA81C28';

    public function test_exact_canonical_operation_matches_content_explorer_source_url_control(): void
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
        $this->assertSame('low', $operation['risk']);
    }

    public function test_media_read_returns_only_authoritative_persisted_source_url_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-source', ['tenant.view', 'sites.view', 'content.view']);
        [$site, $media] = $this->siteAndMedia(
            $membership,
            501,
            'https://alpha.example.test/uploads/hero.jpg',
        );

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha-source/sites/'.$site->id.'/media', 'GET'),
        );
        $this->assertSame(
            ContentApiController::class.'@media',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = MediaItem::withoutGlobalScopes()->findOrFail($media->id)->toArray();

        $this->actingAs($user)
            ->getJson(
                '/api/v1/tenants/alpha-source/sites/'.$site->id
                .'/media?source_url=https%3A%2F%2Fattacker.example%2Fx&per_page=100&page=1',
            )
            ->assertOk()
            ->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath('data.0.source_url', 'https://alpha.example.test/uploads/hero.jpg')
            ->assertJsonMissing(['source_url' => 'https://attacker.example/x']);

        $this->assertSame(
            $before,
            MediaItem::withoutGlobalScopes()->findOrFail($media->id)->toArray(),
        );
    }

    public function test_context_permission_and_tenant_site_boundaries_fail_closed_without_foreign_disclosure(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited-source', ['tenant.view', 'sites.view']);
        [$limitedSite] = $this->siteAndMedia(
            $limitedMembership,
            601,
            'https://limited.example.test/private.jpg',
        );

        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited-source/sites/'.$limitedSite->id.'/media')
            ->assertForbidden();

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha-source-iso', ['tenant.view', 'sites.view', 'content.view']);
        [$alphaSite, $alphaMedia] = $this->siteAndMedia(
            $alpha,
            701,
            'https://alpha.example.test/alpha.jpg',
        );

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta-source-iso', ['tenant.view', 'sites.view', 'content.view']);
        [$betaSite, $betaMedia] = $this->siteAndMedia(
            $beta,
            702,
            'https://beta.example.test/private.jpg',
        );

        $this->actingAs($owner)
            ->get('/tenants/alpha-source-iso/context?site='.$alphaSite->id)
            ->assertOk()
            ->assertJsonPath('active_site.id', $alphaSite->id)
            ->assertJsonPath(
                'api.media',
                '/api/v1/tenants/alpha-source-iso/sites/'.$alphaSite->id.'/media',
            );

        $this->actingAs($owner)
            ->get('/tenants/alpha-source-iso/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson('/api/v1/tenants/alpha-source-iso/sites/'.$betaSite->id.'/media')
            ->assertOk()
            ->assertJsonMissing(['id' => $betaMedia->id])
            ->assertJsonMissing(['source_url' => 'https://beta.example.test/private.jpg']);

        $this->assertDatabaseHas('media_items', ['id' => $alphaMedia->id]);
        $this->assertDatabaseHas('media_items', ['id' => $betaMedia->id]);
    }

    public function test_runtime_wiring_is_explorer_only_safe_read_only_and_operation_specific(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(
            resource_path('js/content-explorer-media-source-link-control.tsx'),
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
            'ContentExplorerMediaSourceLinkControl context={context}',
            $app,
        );
        $this->assertStringContainsString(
            "route.key === 'explorer'",
            $app,
        );
        $this->assertStringContainsString('context.api.media', $control);
        $this->assertStringContainsString('safeContentExplorerExternalUrl(row.source_url)', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringContainsString("authorize('content.view')", $controller);
        $this->assertStringContainsString("where('site_id', $site)", $controller);
        $this->assertStringNotContainsString('method: \'POST\'', $control);
        $this->assertStringNotContainsString('method: \'PATCH\'', $control);
        $this->assertStringNotContainsString('method: \'DELETE\'', $control);
    }

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
            'name' => "source-link-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function siteAndMedia(
        TenantMembership $membership,
        int $remoteId,
        string $sourceUrl,
    ): array {
        return $this->inTenant($membership, function () use ($remoteId, $sourceUrl): array {
            $site = Site::query()->create([
                'name' => "Source {$remoteId}",
                'url' => "https://site-{$remoteId}.example.test",
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
            $media = MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'title' => "media-{$remoteId}.jpg",
                'mime_type' => 'image/jpeg',
                'media_type' => 'image',
                'source_url' => $sourceUrl,
                'remote_modified_at' => now(),
                'synced_at' => now(),
            ]);

            return [$site, $media];
        });
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
