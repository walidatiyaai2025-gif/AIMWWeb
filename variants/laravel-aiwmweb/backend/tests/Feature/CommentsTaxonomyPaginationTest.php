<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommentsTaxonomyPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_pages_are_deterministic_bounded_and_site_scoped(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'comments-pages-alpha', ['tenant.view', 'content.view']);
        $site = $this->site($member, 'Comments Main');
        $otherSite = $this->site($member, 'Comments Other');

        $this->inTenant($member, function () use ($site, $otherSite): void {
            for ($id = 0; $id < 65; $id++) {
                Comment::query()->create([
                    'site_id' => $site->id,
                    'remote_id' => 1000 + $id,
                    'content_remote_id' => 20,
                    'author_name' => 'Needle Author',
                    'author_email' => 'author@example.test',
                    'body' => 'Needle comment '.$id,
                    'status' => 'approved',
                    'remote_created_at' => '2026-11-08 00:00:00',
                ]);
            }
            Comment::query()->create([
                'site_id' => $otherSite->id,
                'remote_id' => 9999,
                'content_remote_id' => 20,
                'author_name' => 'Needle foreign site',
                'body' => 'Must not appear',
                'status' => 'approved',
                'remote_created_at' => '2026-11-08 00:00:00',
            ]);
        });

        $url = "/api/v1/tenants/comments-pages-alpha/sites/{$site->id}/comments";
        foreach ([[1, 1064, 25], [2, 1039, 25], [3, 1014, 15], [999999, 1014, 15]] as [$page, $firstId, $count]) {
            $expectedPage = min($page, 3);
            $this->actingAs($user)->getJson($url."?page={$page}&per_page=25&status=approved&search=Needle")
                ->assertOk()
                ->assertJsonPath('total', 65)
                ->assertJsonPath('current_page', $expectedPage)
                ->assertJsonPath('last_page', 3)
                ->assertJsonCount($count, 'data')
                ->assertJsonPath('data.0.remote_id', $firstId);
        }

        $this->actingAs($user)->getJson($url.'?page=3&per_page=25&search=NoMatchingComment')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('per_page', 25);
        $this->actingAs($user)->getJson($url.'?page=0')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?page=nope')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?page=1000001')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?per_page=101')->assertUnprocessable();

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/comments-pages-alpha/sites/{$otherSite->id}/comments?per_page=25")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.remote_id', 9999);
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/comments-pages-alpha/sites/999999/comments?page=1')
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_taxonomy_pages_are_deterministic_filterable_and_clamp_overflow(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'taxonomy-pages-alpha', ['tenant.view', 'content.view']);
        $site = $this->site($member, 'Taxonomy Main');

        $this->inTenant($member, function () use ($site): void {
            for ($id = 0; $id < 65; $id++) {
                TaxonomyTerm::query()->create([
                    'site_id' => $site->id,
                    'remote_id' => 2000 + $id,
                    'taxonomy' => 'category',
                    'name' => 'Same Name',
                    'slug' => 'same-'.$id,
                    'usage_count' => 10,
                ]);
            }
            TaxonomyTerm::query()->create([
                'site_id' => $site->id,
                'remote_id' => 9999,
                'taxonomy' => 'post_tag',
                'name' => 'Same Name',
                'slug' => 'other-taxonomy',
                'usage_count' => 50,
            ]);
        });

        $url = "/api/v1/tenants/taxonomy-pages-alpha/sites/{$site->id}/taxonomy";
        foreach ([[1, 2000, 25], [2, 2025, 25], [3, 2050, 15], [999999, 2050, 15]] as [$page, $firstId, $count]) {
            $this->actingAs($user)
                ->getJson($url."?page={$page}&per_page=25&taxonomy=category&minimum_usage=5&search=Same")
                ->assertOk()
                ->assertJsonPath('total', 65)
                ->assertJsonPath('current_page', min($page, 3))
                ->assertJsonPath('last_page', 3)
                ->assertJsonCount($count, 'data')
                ->assertJsonPath('data.0.remote_id', $firstId);
        }

        $this->actingAs($user)->getJson($url.'?taxonomy=post_tag&per_page=25')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.remote_id', 9999);
        $this->actingAs($user)->getJson($url.'?page=2&minimum_usage=100')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(0, 'data');
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('per_page', 100);
        $this->actingAs($user)->getJson($url.'?per_page=200')->assertOk()->assertJsonPath('per_page', 200);
        $this->actingAs($user)->getJson($url.'?page=0')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?page=1000001')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?per_page=201')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?minimum_usage=-1')->assertUnprocessable();
        $this->actingAs($user)->getJson($url.'?minimum_usage=NaN')->assertUnprocessable();
    }

    public function test_tenant_permissions_and_foreign_site_ids_are_enforced(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'paging-guard-alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Guard');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'paging-guard-beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta Guard');

        foreach (['comments', 'taxonomy'] as $resource) {
            $this->actingAs($alphaUser)
                ->getJson("/api/v1/tenants/paging-guard-beta/sites/{$betaSite->id}/{$resource}?page=1")
                ->assertNotFound();
        }
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/paging-guard-alpha/sites/{$betaSite->id}/taxonomy?page=1")
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/paging-guard-alpha/sites/{$betaSite->id}/comments?page=1")
            ->assertOk()->assertJsonPath('total', 0);

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'paging-guard-limited', ['tenant.view']);
        $limitedSite = $this->site($limited, 'Limited Guard');
        foreach (['comments', 'taxonomy'] as $resource) {
            $this->actingAs($limitedUser)
                ->getJson("/api/v1/tenants/paging-guard-limited/sites/{$limitedSite->id}/{$resource}?page=1")
                ->assertForbidden();
        }

        auth()->logout();
        foreach (['comments', 'taxonomy'] as $resource) {
            $this->getJson("/api/v1/tenants/paging-guard-alpha/sites/{$alphaSite->id}/{$resource}?page=1")
                ->assertUnauthorized();
        }
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'paging-'.$slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
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
            'url' => 'https://'.str($name)->slug().'.example.test',
            'status' => 'active',
            'connection_status' => 'connected',
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
