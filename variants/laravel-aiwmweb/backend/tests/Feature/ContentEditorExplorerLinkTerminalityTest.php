<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentEditorExplorerLinkTerminalityTest extends TestCase
{
    // Final exact-head CI trigger after corrected parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-607ACDF4D8';

    public function test_exact_canonical_operation_matches_content_editor_explorer_link(): void
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
        $this->assertSame('/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit', $operation['route_screen']);
        $this->assertSame('@ExplorerUrl -> @ExplorerUrl', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_source_and_laravel_control_preserve_back_to_explorer_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('private string ExplorerUrl => $"/sites/{SiteId}/explorer";', $source);
        $this->assertStringContainsString('href="@ExplorerUrl"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorExplorerLinkControl', $control);
        $this->assertStringContainsString('authoritativeContentEditorExplorerHref', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/explorer')", $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_EXPLORER_LINK_OPERATION_ID}', $control);
        $this->assertStringContainsString("<ContentEditorExplorerLinkControl context={context} siteId={siteId ?? ''} editorEndpoint={endpoint} />", $control);
    }

    public function test_owned_editor_context_drives_explorer_target_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Editor');
        $item = $this->content($membership, $site, 101);

        $before = ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/content/post/101/edit")
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertSessionHas('canonical_site_id', $site->id);

        $this->actingAs($user)
            ->getJson("/tenants/alpha/context?site={$site->id}")
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/content/post/101/editor")
            ->assertOk()
            ->assertJsonPath('wordpress_id', 101)
            ->assertJsonPath('type', 'post');

        $this->actingAs($user)
            ->get("/tenants/alpha/explorer?site={$site->id}")
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray());
    }

    public function test_foreign_site_and_foreign_tenant_context_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Editor');
        $this->content($alpha, $alphaSite, 101);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta Editor');
        $this->content($beta, $betaSite, 202);

        $this->actingAs($alphaUser)
            ->getJson("/tenants/alpha/context?site={$betaSite->id}")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/tenants/beta/context?site={$betaSite->id}")
            ->assertNotFound();
    }

    public function test_explorer_link_control_is_navigation_only_and_cannot_override_identity(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function authoritativeContentEditorExplorerHref');
        $end = strpos($control, 'export type ContentEditorSnapshot', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString("method: 'POST'", $slice);
        $this->assertStringNotContainsString("method: 'PATCH'", $slice);
        $this->assertStringNotContainsString("method: 'DELETE'", $slice);
        $this->assertStringNotContainsString('tenant_id', $slice);
        $this->assertStringNotContainsString('site_id', $slice);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');
    }

    /** @param list<string> $permissions */
    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): TenantMembership
    {
        app(TenantContext::class)->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }

    private function content(TenantMembership $membership, Site $site, int $remoteId): ContentItem
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'title' => 'Explorer link parity',
            'slug' => 'explorer-link-parity',
            'body' => '<p>Body</p>',
            'excerpt' => 'Excerpt',
            'status' => 'draft',
            'link' => "https://wordpress.example.test/?p={$remoteId}",
            'remote_hash' => hash('sha256', 'explorer-link-'.$remoteId),
            'remote_modified_at' => '2026-10-24T06:00:00Z',
            'remote_version' => 'wp-v1',
            'synced_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $item;
    }
}
