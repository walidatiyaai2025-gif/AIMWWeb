<?php

namespace Tests\Feature;

use App\Jobs\BulkContentMutationJob;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerBulkDraftTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-F7BCCD650C';

    public function test_real_source_control_and_laravel_queue_contract_are_connected(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/content-explorer-bulk-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-explorer-bulk-draft-control.ts'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('private Task BulkDraftAsync() => QueueBulkStatusAsync("draft")', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.ContentEdit', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentExplorerBulkTrashControl', $app);
        $this->assertStringContainsString('CONTENT_EXPLORER_BULK_DRAFT_OPERATION_ID', $widget);
        $this->assertStringContainsString('draftMutation.mutate(selectedLocalIds)', $widget);
        $this->assertStringContainsString("action: 'draft'", $control);
        $this->assertStringContainsString("'state' => 'queued'", (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php')));

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/sites/7/content/bulk', 'POST'),
        );
        $this->assertSame('App\Http\Controllers\ContentApiController@bulk', ltrim($route->getActionName(), '\\'));
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_editor_queues_draft_for_tenant_owned_selected_posts_and_pages_without_pretending_remote_completion(): void
    {
        Queue::fake();

        $editor = User::factory()->create();
        $membership = $this->membership($editor, 'draft-alpha', ['content.view', 'content.edit']);
        [$site, $post, $page] = $this->siteAndItems($membership);

        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/draft-alpha/sites/{$site->id}/content/bulk",
            ['ids' => [$post->id, $page->id], 'action' => 'draft', 'payload' => []],
        )->assertStatus(202)
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('count', 2);

        Queue::assertPushed(BulkContentMutationJob::class, function (BulkContentMutationJob $job) use ($site, $post, $page): bool {
            return $job->siteId === $site->id
                && $job->action === 'draft'
                && $job->contentIds === [$post->id, $page->id];
        });

        $this->assertSame('publish', $post->fresh()->status);
        $this->assertSame('publish', $page->fresh()->status);
    }

    public function test_guest_viewer_foreign_tenant_foreign_site_and_missing_local_id_fail_closed(): void
    {
        Queue::fake();

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'draft-alpha', ['content.view', 'content.edit']);
        [$site, $post] = $this->siteAndItems($alpha);

        $viewer = User::factory()->create();
        $this->membershipInTenant($viewer, $alpha->tenant, ['content.view'], 'draft-viewer');

        $foreignOwner = User::factory()->create();
        $foreign = $this->membership($foreignOwner, 'draft-beta', ['content.view', 'content.edit']);
        [$foreignSite, $foreignPost] = $this->siteAndItems($foreign);

        $url = "/api/v1/tenants/draft-alpha/sites/{$site->id}/content/bulk";
        $payload = ['ids' => [$post->id], 'action' => 'draft', 'payload' => []];

        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs($viewer)->postJson($url, $payload)->assertForbidden();
        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/draft-beta/sites/{$foreignSite->id}/content/bulk",
            ['ids' => [$foreignPost->id], 'action' => 'draft'],
        )->assertNotFound();
        $this->actingAs($editor)->postJson(
            "/api/v1/tenants/draft-alpha/sites/{$foreignSite->id}/content/bulk",
            ['ids' => [$foreignPost->id], 'action' => 'draft'],
        )->assertNotFound();
        $this->actingAs($editor)->postJson($url, [
            'ids' => [$foreignPost->id], 'action' => 'draft',
        ])->assertUnprocessable();

        $this->actingAs($editor)->postJson($url, [
            'ids' => [$post->id], 'action' => 'draft', 'tenant_id' => $alpha->tenant_id,
        ])->assertUnprocessable();

        Queue::assertNotPushed(BulkContentMutationJob::class);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipInTenant($user, $tenant, $permissions, "{$slug}-editor");
    }

    private function membershipInTenant(User $user, Tenant $tenant, array $permissions, string $name): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $name.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function siteAndItems(TenantMembership $membership): array
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        try {
            $site = Site::query()->create([
                'name' => 'Draft Explorer',
                'url' => 'https://draft-explorer.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
            $post = ContentItem::query()->create([
                'site_id' => $site->id, 'remote_id' => 101,
                'type' => 'post', 'title' => 'Draft post',
                'slug' => 'draft-post', 'status' => 'publish',
                'synced_at' => now(),
            ]);
            $page = ContentItem::query()->create([
                'site_id' => $site->id, 'remote_id' => 102,
                'type' => 'page', 'title' => 'Draft page',
                'slug' => 'draft-page', 'status' => 'publish',
                'synced_at' => now(),
            ]);

            return [$site, $post, $page];
        } finally {
            $context->forget();
        }
    }
}
