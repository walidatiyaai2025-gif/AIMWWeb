<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\ContentRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentRevisionsPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revisions_are_bounded_stable_and_tenant_isolated(): void
    {
        [$user, $tenant] = $this->member('revision-alpha', ['content.view']);
        [$other, $foreignTenant] = $this->member('revision-beta', ['content.view']);
        $ctx = app(TenantContext::class);
        $ctx->activate($tenant);
        $item = ContentItem::query()->create(['site_id' => 21, 'remote_id' => 101, 'type' => 'post', 'title' => 'Alpha']);
        for ($i = 1; $i <= 27; $i++) {
            ContentRevision::query()->create([
                'site_id' => 21, 'content_item_id' => $item->id,
                'remote_id' => $i, 'snapshot' => ['title' => 'Alpha'],
                'content_hash' => str_repeat('a', 64),
            ]);
        }
        $ctx->activate($foreignTenant);
        $foreignItem = ContentItem::query()->create(['site_id' => 22, 'remote_id' => 202, 'type' => 'post', 'title' => 'Beta']);
        ContentRevision::query()->create([
            'site_id' => 22, 'content_item_id' => $foreignItem->id,
            'remote_id' => 1, 'snapshot' => ['title' => 'Beta'],
            'content_hash' => str_repeat('b', 64),
        ]);
        $ctx->forget();

        $url = "/api/v1/tenants/revision-alpha/sites/21/content/items/{$item->id}/revisions";
        $first = $this->actingAs($user)->getJson($url.'?page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 27)
            ->assertJsonPath('last_page', 3)->assertJsonCount(10, 'data');
        $second = $this->actingAs($user)->getJson($url.'?page=2&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonCount(10, 'data');
        $this->assertSame([], array_values(array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        )));
        $this->actingAs($user)->getJson($url.'?page=999999&per_page=10')
            ->assertOk()->assertJsonPath('current_page', 3)
            ->assertJsonCount(7, 'data');
        $this->actingAs($user)->getJson($url)
            ->assertOk()->assertJsonPath('per_page', 50)->assertJsonCount(27, 'data');
        foreach (['page=0', 'page=1000001', 'page=no', 'per_page=500', 'per_page=0'] as $bad) {
            $this->actingAs($user)->getJson($url.'?'.$bad)->assertUnprocessable();
        }
        $this->actingAs($user)->getJson(
            "/api/v1/tenants/revision-alpha/sites/22/content/items/{$foreignItem->id}/revisions?page=1"
        )->assertNotFound();
        $this->actingAs($other)->getJson(
            "/api/v1/tenants/revision-beta/sites/22/content/items/{$foreignItem->id}/revisions?page=1"
        )->assertOk()->assertJsonPath('total', 1);
    }

    private function member(string $slug, array $permissions): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => $slug]);
        $ctx = app(TenantContext::class);
        $ctx->activate($tenant);
        try {
            $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
            $role = Role::query()->create(['name' => $slug.'-'.$user->id]);
            foreach ($permissions as $name) {
                $permission = Permission::query()->firstOrCreate(['name' => $name]);
                $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
            }
            $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        } finally {
            $ctx->forget();
        }

        return [$user, $tenant];
    }
}
