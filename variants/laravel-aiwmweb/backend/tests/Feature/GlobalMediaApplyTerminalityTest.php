<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GlobalMediaApplyTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-D805D5D9A8';

    public function test_source_apply_click_uses_real_media_filters_and_authoritative_read(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalMediaExplorer.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/global-media-apply-control.tsx'));
        $page = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString('@onclick="ApplyAsync"', $source);
        $this->assertStringContainsString('ApplyAsync(){_page=1;await LoadAsync();}', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('globalMediaApplyQuery', $control);
        $this->assertStringContainsString('authoritativeGlobalMediaApplyEndpoint(context)', $page);
        $this->assertStringContainsString('applyGlobalMediaFilters', $page);
        $this->assertStringContainsString('setPage(1)', $page);
        $this->assertStringContainsString('setMediaAppliedFilters({ ...mediaDraft })', $page);
        $this->assertStringContainsString('queryClient', $page);
        $this->assertStringContainsString("Site::query()->findOrFail(\$site);", $controller);
    }

    public function test_apply_filters_uses_real_site_scoped_media_and_page_one_with_selected_size(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-media-apply', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Apply');
        $otherSite = $this->site($membership, 'Other Apply');

        $this->inTenant($membership, function () use ($site, $otherSite): void {
            foreach ([
                [701, 'Needle image one', 'image/jpeg'],
                [702, 'Needle image two', 'image/png'],
                [703, 'Needle video', 'video/mp4'],
                [704, 'Other image', 'image/jpeg'],
            ] as [$id, $title, $mime]) {
                MediaItem::query()->create([
                    'site_id' => $site->id,
                    'remote_id' => $id,
                    'title' => $title,
                    'alt_text' => $title,
                    'mime_type' => $mime,
                ]);
            }
            MediaItem::query()->create([
                'site_id' => $otherSite->id,
                'remote_id' => 801,
                'title' => 'Needle foreign-site image',
                'mime_type' => 'image/jpeg',
            ]);
        });

        $endpoint = "/api/v1/tenants/alpha-media-apply/sites/{$site->id}/media";
        $this->actingAs($user)->getJson($endpoint.'?search=Needle&mime_type=image&per_page=12&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 12);

        $this->actingAs($user)->getJson($endpoint.'?search=Needle&mime_type=video&per_page=24&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.remote_id', 703);

        $this->actingAs($user)->getJson($endpoint.'?per_page=48&page=1')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('per_page', 48)
            ->assertJsonPath('current_page', 1);
    }

    public function test_apply_read_fails_closed_for_foreign_tenant_foreign_site_and_missing_permission(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha-apply-guard', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Guard');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-apply-guard', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta Guard');

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta-apply-guard/sites/{$betaSite->id}/media?page=1&per_page=24")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha-apply-guard/sites/{$betaSite->id}/media?page=1&per_page=24")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha-apply-guard/sites/{$alphaSite->id}/media?page=1&per_page=24")
            ->assertOk();

        $readDenied = User::factory()->create();
        $this->membership($readDenied, 'denied-apply-guard', ['tenant.view']);
        $this->actingAs($readDenied)
            ->getJson("/api/v1/tenants/denied-apply-guard/sites/{$alphaSite->id}/media?per_page=24")
            ->assertForbidden(); // 403: permission gate is independent of caller-controlled site IDs.
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
        $role = Role::query()->create(['name' => "global-media-apply-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
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
