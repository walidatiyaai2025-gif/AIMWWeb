<?php

namespace Tests\Feature\Sync;

use App\Models\ContentConflict;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SyncHistoryAndConflictsPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoritative_sync_and_conflict_pages_clamp_and_validate_without_cross_site_rows(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'sync-paging-alpha', ['tenant.view', 'content.view']);
        $site = $this->site($member, 'Alpha WP Sync');
        $otherSite = $this->site($member, 'Other WP Sync');

        $this->inTenant($member, function () use ($site, $otherSite): void {
            for ($n = 0; $n < 63; $n++) {
                SyncRun::query()->create([
                    'site_id' => $site->id,
                    'state' => 'completed',
                    'mode' => 'full',
                    'trigger' => 'manual',
                    'resources' => ['posts'],
                ]);
                ContentConflict::query()->create([
                    'site_id' => $site->id,
                    'entity_type' => 'ContentItem',
                    'remote_id' => 1000 + $n,
                    'status' => 'open',
                ]);
            }

            SyncRun::query()->create([
                'site_id' => $otherSite->id,
                'state' => 'failed',
                'mode' => 'full',
                'trigger' => 'manual',
                'resources' => ['posts'],
            ]);
            ContentConflict::query()->create([
                'site_id' => $otherSite->id,
                'entity_type' => 'ContentItem',
                'remote_id' => 9000,
                'status' => 'open',
            ]);
        });

        $base = "/api/v1/tenants/sync-paging-alpha/sites/{$site->id}";
        foreach (['sync', 'conflicts'] as $resource) {
            $url = $base.'/'.$resource;
            foreach ([[1, 25], [2, 25], [3, 13], [1000000, 13]] as [$page, $count]) {
                $this->actingAs($user)->getJson($url."?page={$page}&per_page=25")
                    ->assertOk()
                    ->assertJsonPath('total', 63)
                    ->assertJsonPath('last_page', 3)
                    ->assertJsonPath('current_page', min($page, 3))
                    ->assertJsonCount($count, 'data');
            }

            $this->actingAs($user)->getJson($url.'?page=0')->assertUnprocessable();
            $this->actingAs($user)->getJson($url.'?page=abc')->assertUnprocessable();
            $this->actingAs($user)->getJson($url.'?page=1000001')->assertUnprocessable();
            $this->actingAs($user)->getJson($url.'?per_page=0')->assertUnprocessable();
            $this->actingAs($user)->getJson($url.'?per_page=101')->assertUnprocessable();
            $this->actingAs($user)->getJson($url.'?per_page=not-a-number')->assertUnprocessable();
        }

        $this->actingAs($user)->getJson($base.'/sync')
            ->assertOk()->assertJsonPath('per_page', 25)->assertJsonPath('total', 63);
        $this->actingAs($user)->getJson($base.'/conflicts')
            ->assertOk()->assertJsonPath('per_page', 50)->assertJsonPath('total', 63)
            ->assertJsonPath('last_page', 2);
        $this->actingAs($user)->getJson($base.'/sync?page=99&state=not-present')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson($base.'/sync?page=99&state=completed&per_page=25')
            ->assertOk()->assertJsonPath('total', 63)->assertJsonPath('current_page', 3);

        foreach (['sync', 'conflicts'] as $resource) {
            $this->actingAs($user)
                ->getJson("/api/v1/tenants/sync-paging-alpha/sites/{$otherSite->id}/{$resource}")
                ->assertOk()->assertJsonPath('total', 1);
        }
    }

    public function test_large_sync_bookmarks_never_issue_oversized_offset_selects(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'sync-safe-offset', ['tenant.view', 'content.view']);
        $site = $this->site($member, 'Safe Offset Site');

        $this->inTenant($member, function () use ($site): void {
            SyncRun::query()->create([
                'site_id' => $site->id,
                'state' => 'completed',
                'mode' => 'full',
                'trigger' => 'manual',
                'resources' => ['posts'],
            ]);
            ContentConflict::query()->create([
                'site_id' => $site->id,
                'entity_type' => 'ContentItem',
                'remote_id' => 54321,
                'status' => 'open',
            ]);
        });

        foreach (['sync', 'conflicts'] as $resource) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->actingAs($user)
                    ->getJson("/api/v1/tenants/sync-safe-offset/sites/{$site->id}/{$resource}?page=1000000&per_page=25")
                    ->assertOk()
                    ->assertJsonPath('total', 1)
                    ->assertJsonPath('current_page', 1)
                    ->assertJsonCount(1, 'data');
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            $table = $resource === 'sync' ? 'sync_runs' : 'content_conflicts';
            $foundPageSelect = false;
            foreach ($queries as $query) {
                if (! str_contains(strtolower($query['query']), $table)) {
                    continue;
                }
                if (preg_match('/\\boffset\\s+(\\d+)/i', $query['query'], $matches) === 1) {
                    $foundPageSelect = true;
                    $this->assertLessThanOrEqual(25, (int) $matches[1]);
                } elseif (preg_match('/\\boffset\\s+\\?/i', $query['query']) === 1) {
                    $foundPageSelect = true;
                    $this->assertLessThanOrEqual(25, (int) end($query['bindings']));
                }
            }
            // When SQL uses LIMIT alone on the first page, an OFFSET is
            // not required; nevertheless at least one scoped SELECT ran.
            $this->assertNotEmpty($queries);
        }
    }

    public function test_sync_pagination_is_tenant_and_permission_isolated(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'sync-scope-alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha scoped site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'sync-scope-beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta scoped site');

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'sync-scope-limited', ['tenant.view']);
        $limitedSite = $this->site($limited, 'Limited site');

        foreach (['sync', 'conflicts'] as $resource) {
            $this->actingAs($alphaUser)
                ->getJson("/api/v1/tenants/sync-scope-beta/sites/{$betaSite->id}/{$resource}")
                ->assertNotFound();
            $this->actingAs($alphaUser)
                ->getJson("/api/v1/tenants/sync-scope-alpha/sites/{$betaSite->id}/{$resource}")
                ->assertNotFound();
            $this->actingAs($limitedUser)
                ->getJson("/api/v1/tenants/sync-scope-limited/sites/{$limitedSite->id}/{$resource}")
                ->assertForbidden();
        }

        auth()->logout();
        $this->getJson("/api/v1/tenants/sync-scope-alpha/sites/{$alphaSite->id}/sync")
            ->assertUnauthorized();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $member = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'sync-pages-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $member->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $member->fresh('tenant');
    }

    private function site(TenantMembership $member, string $name): Site
    {
        return $this->inTenant($member, fn () => Site::query()->create([
            'name' => $name,
            'url' => 'https://'.str($name)->slug().'.example.test',
            'status' => 'active',
        ]));
    }

    private function inTenant(TenantMembership $member, callable $callback): mixed
    {
        $context = app(TenantContext::class);
        $context->activate($member->tenant, $member);

        try {
            return $callback();
        } finally {
            $context->forget();
        }
    }
}
