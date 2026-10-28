<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MediaOptimizationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_and_foreign_tenant_site_media_cannot_edit_or_read(): void
    {
        $viewer = User::factory()->create();
        $limited = $this->membership($viewer, 'viewer', ['content.view']);
        [$viewerSite, $viewerMedia] = $this->media($limited, 501);
        $this->actingAs($viewer)
            ->patchJson("/api/v1/tenants/viewer/sites/{$viewerSite->id}/media/{$viewerMedia->id}", ['title' => 'No'])
            ->assertForbidden(); // 403: viewer cannot save metadata

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['content.view', 'content.edit']);
        [$alphaSite, $alphaMedia] = $this->media($alpha, 601);
        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['content.view', 'content.edit']);
        [$betaSite, $betaMedia] = $this->media($beta, 602);

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta/sites/{$betaSite->id}/media/{$betaMedia->id}")
            ->assertNotFound(); // 404 foreign tenant
        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha/sites/{$betaSite->id}/media/{$betaMedia->id}")
            ->assertNotFound(); // 404 foreign site
        $this->actingAs($alphaUser)
            ->patchJson("/api/v1/tenants/alpha/sites/{$alphaSite->id}/media/{$betaMedia->id}", ['title' => 'No'])
            ->assertNotFound(); // 404 foreign media
        $this->assertDatabaseHas('media_items', ['id' => $alphaMedia->id]);
        $this->assertDatabaseHas('media_items', ['id' => $betaMedia->id]);
        Http::assertNothingSent();
    }

    public function test_save_rejects_caller_supplied_tenant_identity_without_remote_side_effect(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->media($member, 701);
        $this->actingAs($user)
            ->patchJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}", [
                'title' => 'Tampered',
                'tenant_id' => $member->tenant_id,
                'site_id' => 99999,
                'remote_id' => 99999,
            ])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_editor_get_reads_current_wordpress_metadata_and_does_not_trust_stale_local_title(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->media($member, 801);
        $this->credential($member, $site);
        Http::fake([
            '*' => Http::response([
                'id' => 801,
                'modified_gmt' => '2026-10-28T01:00:00',
                'title' => ['raw' => 'Current WordPress title'],
                'slug' => 'new-slug',
                'alt_text' => 'Authoritative alt',
                'caption' => ['raw' => 'Live caption'],
                'description' => ['raw' => 'Live description'],
            ]),
        ]);
        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}")
            ->assertOk()
            ->assertJsonPath('remote_id', 801)
            ->assertJsonPath('title', 'Current WordPress title')
            ->assertJsonPath('caption', 'Live caption');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/wp/v2/media/801'));
    }

    public function test_save_requires_wordpress_reread_and_rejects_unverified_mutation(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->media($member, 901);
        $this->credential($member, $site);

        Http::fakeSequence()
            ->push(['id' => 901, 'modified_gmt' => '2026-10-28T01:00:00'], 200)
            ->push(['id' => 901], 200)
            ->push(['id' => 901, 'title' => ['raw' => 'Old remote title']], 200);

        $this->actingAs($user)
            ->patchJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}", [
                'title' => 'New title',
                'expected_modified_gmt' => '2026-10-28T01:00:00',
            ])
            ->assertStatus(500); // A stale remote reread must not be reported as a verified save.

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/wp-json/wp/v2/media/901'));
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/wp-json/wp/v2/media/901'));
    }

    public function test_stale_wordpress_edit_is_a_409_with_no_remote_mutation(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', ['content.view', 'content.edit']);
        [$site, $media] = $this->media($member, 1001);
        $this->credential($member, $site);
        Http::fake(['*' => Http::response(['id' => 1001, 'modified_gmt' => '2026-10-28T03:00:00'])]);

        $this->actingAs($user)
            ->patchJson("/api/v1/tenants/alpha/sites/{$site->id}/media/{$media->id}", [
                'title' => 'Overwrites newer content',
                'expected_modified_gmt' => '2026-10-28T01:00:00',
            ])->assertConflict(); // 409

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $member = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "media-optimization-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $member->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $member->fresh('tenant');
    }

    /** @return array{0: Site, 1: MediaItem} */
    private function media(TenantMembership $member, int $remoteId): array
    {
        return $this->inTenant($member, function () use ($remoteId): array {
            $site = Site::query()->create([
                'name' => "Media {$remoteId}",
                'url' => "https://media-{$remoteId}.example.test",
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
            $media = MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'title' => "stale-{$remoteId}.jpg",
                'mime_type' => 'image/jpeg',
            ]);

            return [$site, $media];
        });
    }

    private function credential(TenantMembership $member, Site $site): void
    {
        $this->inTenant($member, fn () => SiteCredential::query()->create([
            'site_id' => $site->id,
            'username' => 'editor',
            'secret_value' => 'application-password-1234',
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
