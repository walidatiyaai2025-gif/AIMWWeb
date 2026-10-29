<?php

namespace Tests\Feature;

use App\Http\Controllers\GlobalPagesPublishController;
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

final class GlobalPagesPublishSelectedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-ECAB44A389';

    public function test_source_route_and_production_control_are_bound_to_publish_selected(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalPagesExplorer.razor'));
        $widget = (string) file_get_contents(resource_path('js/global-pages-confirm-trash-widget.tsx'));
        $control = (string) file_get_contents(resource_path('js/global-pages-publish-selected-control.ts'));

        $this->assertStringContainsString('PublishSelectedAsync() => QueueStatusAsync("publish")', $source);
        $this->assertStringContainsString('GlobalContentBulkPolicy.GroupBySite', $source);
        $this->assertStringContainsString('BulkQueue.QueueAsync', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('GLOBAL_PAGES_PUBLISH_SELECTED_OPERATION_ID', $widget);
        $this->assertStringContainsString('publishMutation.mutate(selectedTargets)', $widget);

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/global-pages/publish', 'POST'),
        );

        $this->assertSame(GlobalPagesPublishController::class.'@publish', ltrim($route->getActionName(), '\\'));
        $this->assertSame('api.v1.global-pages.publish', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_publish_groups_selected_pages_into_one_unique_tenant_job_per_site(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$siteA, $pageA] = $this->siteAndPage($membership, 101);
        [, $pageB] = $this->siteAndPage($membership, 102, $siteA);
        [$siteB, $pageC] = $this->siteAndPage($membership, 201);

        $this->actingAs($user)
            ->postJson('/api/v1/tenants/alpha/global-pages/publish', [
                'targets' => [
                    ['site_id' => $siteA->id, 'wordpress_id' => 101],
                    ['site_id' => $siteA->id, 'wordpress_id' => 102],
                    ['site_id' => $siteB->id, 'wordpress_id' => 201],
                ],
            ])
            ->assertStatus(202)
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('count', 3)
            ->assertJsonPath('site_jobs', 2);

        Queue::assertPushed(BulkContentMutationJob::class, 2);
        Queue::assertPushed(BulkContentMutationJob::class, function (BulkContentMutationJob $job) use ($membership, $siteA, $pageA, $pageB): bool {
            return $job->tenantId === $membership->tenant_id
                && $job->siteId === $siteA->id
                && $job->action === 'publish'
                && $job->contentIds === [$pageA->id, $pageB->id];
        });
        Queue::assertPushed(BulkContentMutationJob::class, function (BulkContentMutationJob $job) use ($membership, $siteB, $pageC): bool {
            return $job->tenantId === $membership->tenant_id
                && $job->siteId === $siteB->id
                && $job->action === 'publish'
                && $job->contentIds === [$pageC->id];
        });
    }

    public function test_guest_viewer_foreign_tenant_foreign_site_identity_injection_and_duplicates_fail_closed(): void
    {
        Queue::fake();

        $editor = User::factory()->create();
        $alpha = $this->membership($editor, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite] = $this->siteAndPage($alpha, 301);

        $viewer = User::factory()->create();
        $this->membershipExisting($viewer, $alpha->tenant, ['content.view'], 'viewer');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['content.view', 'content.edit']);
        [$betaSite] = $this->siteAndPage($beta, 401);

        $payload = ['targets' => [['site_id' => $alphaSite->id, 'wordpress_id' => 301]]];

        $this->postJson('/api/v1/tenants/alpha/global-pages/publish', $payload)->assertUnauthorized();

        $this->actingAs($viewer)
            ->postJson('/api/v1/tenants/alpha/global-pages/publish', $payload)
            ->assertForbidden();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/beta/global-pages/publish', [
                'targets' => [['site_id' => $betaSite->id, 'wordpress_id' => 401]],
            ])
            ->assertNotFound();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/alpha/global-pages/publish', [
                'targets' => [['site_id' => $betaSite->id, 'wordpress_id' => 401]],
            ])
            ->assertUnprocessable();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/alpha/global-pages/publish', [
                'tenant_id' => $alpha->tenant_id,
                'targets' => [['site_id' => $alphaSite->id, 'wordpress_id' => 301]],
            ])
            ->assertUnprocessable();

        $this->actingAs($editor)
            ->postJson('/api/v1/tenants/alpha/global-pages/publish', [
                'targets' => [
                    ['site_id' => $alphaSite->id, 'wordpress_id' => 301],
                    ['site_id' => $alphaSite->id, 'wordpress_id' => 301],
                ],
            ])
            ->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipExisting($user, $tenant, $permissions, $slug.'-role');
    }

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

    private function siteAndPage(TenantMembership $membership, int $remoteId, ?Site $site = null): array
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);

        $site ??= Site::query()->create([
            'name' => 'Publish '.$remoteId,
            'url' => "https://publish-{$remoteId}.example.test",
            'status' => 'active',
            'connection_status' => 'connected',
        ]);

        $page = ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'page',
            'title' => 'Page '.$remoteId,
            'slug' => 'post-'.$remoteId,
            'status' => 'draft',
            'metadata' => [],
        ]);

        app(TenantContext::class)->forget();

        return [$site, $page];
    }
}
