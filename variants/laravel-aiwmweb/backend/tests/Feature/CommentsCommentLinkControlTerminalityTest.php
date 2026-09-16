<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use App\Models\Comment;
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

class CommentsCommentLinkControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-COMM-B16FBF4792';

    public function test_exact_canonical_input_is_the_pending_wordpress_comment_link_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('comments', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/comments', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/CommentsManager.razor', $operation['current_source']);
        $this->assertSame('@comment.Link -> @comment.Link', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
        $this->assertTrue((bool) $operation['native_wp_rest']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_comments_read_is_session_tenant_and_permission_guarded_and_returns_persisted_link_only(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $comment = $this->comment($membership, $site, 71, 'Alice', 'https://alpha.example.test/post#comment-71');

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/'.$site->id.'/comments', 'GET'));
        $this->assertSame(ContentApiController::class.'@comments', ltrim($route->getActionName(), '\\'));
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $before = Comment::withoutGlobalScopes()->findOrFail($comment->id)->toArray();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/sites/'.$site->id.'/comments?link=https%3A%2F%2Fattacker.example%2Foverride')
            ->assertOk()
            ->assertJsonPath('data.0.id', $comment->id)
            ->assertJsonPath('data.0.link', 'https://alpha.example.test/post#comment-71')
            ->assertJsonMissing(['link' => 'https://attacker.example/override']);

        $after = Comment::withoutGlobalScopes()->findOrFail($comment->id)->toArray();
        $this->assertSame($before, $after, 'The read-only comment link path must not mutate authoritative persistence.');
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_navigation_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $this->comment($alphaMembership, $alphaSite, 71, 'Alice', 'https://alpha.example.test/comment-71');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->comment($betaMembership, $betaSite, 88, 'Beta Secret', 'https://beta.example.test/private-comment');

        $this->getJson('/api/v1/tenants/alpha/sites/'.$alphaSite->id.'/comments')->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/sites/'.$limitedSite->id.'/comments')
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/beta/sites/'.$betaSite->id.'/comments')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/comments')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha/sites/'.$betaSite->id.'/comments')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['link' => 'https://beta.example.test/private-comment']);
    }

    public function test_production_wiring_allows_only_absolute_http_links_and_exposes_no_write_or_secret_path(): void
    {
        $component = (string) file_get_contents(resource_path('js/comments-comment-link-control.tsx'));
        $host = (string) file_get_contents(resource_path('js/app.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));
        $service = (string) file_get_contents(app_path('Content/ContentPlatformService.php'));

        $linkControlStart = strpos($component, 'export function CommentsCommentLinkControl');
        $replyDraftStart = strpos($component, 'function CommentsReplyDraft');
        $this->assertNotFalse($linkControlStart);
        $this->assertNotFalse($replyDraftStart);
        $this->assertGreaterThan($linkControlStart, $replyDraftStart);
        $linkControl = substr($component, $linkControlStart, $replyDraftStart - $linkControlStart);

        $this->assertStringContainsString(self::OPERATION_ID, $linkControl);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $component);
        $this->assertStringContainsString('parsed.username || parsed.password', $component);
        $this->assertStringContainsString('target="_blank"', $linkControl);
        $this->assertStringContainsString('rel="noopener noreferrer"', $linkControl);
        $this->assertStringContainsString("import { CommentsCommentLinksControl } from './comments-comment-link-control';", $host);
        $this->assertStringContainsString("if (route.key === 'comments') return <><CommentsBackToSitesControl context={context} /><CommentsCommentLinksControl context={context} />", $host);
        $this->assertStringContainsString("\$q = Comment::query()->where('site_id', \$site);", $controller);
        $this->assertStringContainsString("'link' => \$row['link'] ?? null", $service);
        $this->assertStringNotContainsString('apiRequest', $linkControl);
        $this->assertStringNotContainsString('method: \'POST\'', $linkControl);
        $this->assertStringNotContainsString('method: \'PATCH\'', $linkControl);
        $this->assertStringNotContainsString('method: \'DELETE\'', $linkControl);
        $this->assertStringNotContainsString('secret', strtolower($linkControl));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "comment-link-{$slug}-{$user->id}"]);
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
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function comment(TenantMembership $membership, Site $site, int $remoteId, string $author, string $link): Comment
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $comment = Comment::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'author_name' => $author,
            'author_email' => strtolower(str_replace(' ', '.', $author)).'@example.test',
            'body' => 'Persisted comment body',
            'status' => 'approved',
            'link' => $link,
            'remote_hash' => hash('sha256', $link),
            'synced_at' => now(),
        ]);
        $context->forget();

        return $comment;
    }
}
