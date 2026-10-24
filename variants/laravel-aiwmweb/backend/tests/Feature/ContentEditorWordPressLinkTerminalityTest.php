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

final class ContentEditorWordPressLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-2C51372C70';

    public function test_exact_canonical_operation_matches_content_editor_wordpress_link(): void
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
        $this->assertSame('@_model.Link -> @_model.Link', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_editor_read_returns_only_authoritative_persisted_link_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view']);
        $site = $this->site($membership, 'Alpha Editor');
        $item = $this->content($membership, $site, 101, 'https://alpha.example.test/?p=101');

        $before = ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray();

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/content/post/101/editor?link=https%3A%2F%2Fattacker.example%2Fx")
            ->assertOk()
            ->assertJsonPath('id', $item->id)
            ->assertJsonPath('wordpress_id', 101)
            ->assertJsonPath('type', 'post')
            ->assertJsonPath('link', 'https://alpha.example.test/?p=101')
            ->assertJsonMissing(['link' => 'https://attacker.example/x']);

        $this->assertSame($before, ContentItem::withoutGlobalScopes()->findOrFail($item->id)->toArray());
    }

    public function test_cross_tenant_site_and_missing_permission_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Editor');
        $this->content($alpha, $alphaSite, 101, 'https://alpha.example.test/?p=101');

        $limited = User::factory()->create();
        $this->membershipExisting($limited, $alpha->tenant, [], 'limited');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['content.view']);
        $betaSite = $this->site($beta, 'Beta Editor');
        $this->content($beta, $betaSite, 202, 'https://beta.example.test/?p=202');

        $this->actingAs($limited)
            ->getJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/content/post/101/editor")
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta/sites/{$betaSite->id}/content/post/202/editor")
            ->assertNotFound();
    }

    public function test_source_and_laravel_control_preserve_new_tab_read_only_semantics_and_url_safety(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString(
            '@if (!string.IsNullOrWhiteSpace(_model.Link)) { <a class="btn" href="@_model.Link" target="_blank">',
            $source,
        );
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorWordPressLinkControl', $control);
        $this->assertStringContainsString('<ContentEditorWordPressLinkControl value={snapshot.link} />', $control);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringContainsString("'link' => (string) (\$item->link ?? '')", $controller);
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

    private function content(TenantMembership $membership, Site $site, int $remoteId, string $link): ContentItem
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $item = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'title' => 'Editor link parity',
            'slug' => 'editor-link-parity',
            'body' => '<p>Body</p>',
            'excerpt' => 'Excerpt',
            'status' => 'publish',
            'link' => $link,
            'remote_hash' => hash('sha256', $link),
            'remote_modified_at' => '2026-10-24T06:00:00Z',
            'remote_version' => 'wp-v1',
            'synced_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        return $item;
    }
}
