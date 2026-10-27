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

final class GlobalMediaResetTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-9B87CACA17';

    public function test_source_reset_semantics_are_bound_to_the_real_laravel_media_read(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalMediaExplorer.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/global-media-reset-control.tsx'));
        $page = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString('@onclick="ResetAsync"', $source);
        $this->assertStringContainsString(
            'ResetAsync(){_query=string.Empty;_siteFilter=string.Empty;_typeFilter="all";_pageSize=24;_page=1;await LoadAsync();}',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("search: ''", $control);
        $this->assertStringContainsString("mediaType: 'all'", $control);
        $this->assertStringContainsString('pageSize: 24', $control);
        $this->assertStringContainsString('page: 1', $control);
        $this->assertStringContainsString('authoritativeGlobalMediaEndpoint(context)', $page);
        $this->assertStringContainsString('resetGlobalMediaFilters', $page);
        $this->assertStringContainsString('queryClient.fetchQuery', $page);
        $this->assertStringContainsString('per_page: String(reset.pageSize)', $page);

        $this->assertStringContainsString("authorize('content.view')", $controller);
        $this->assertStringContainsString('Site::query()->findOrFail($site);', $controller);
        $this->assertStringContainsString('$request->query(\'search\')', $controller);
        $this->assertStringContainsString('$request->query(\'mime_type\')', $controller);
        $this->assertStringContainsString('$request->query(\'per_page\', 30)', $controller);
    }

    public function test_reset_rereads_unfiltered_authoritative_media_for_the_active_tenant_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-media-reset', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Media Reset');

        $this->inTenant($membership, function () use ($site): void {
            MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 701,
                'title' => 'Needle image',
                'alt_text' => 'Needle',
                'mime_type' => 'image/jpeg',
            ]);
            MediaItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 702,
                'title' => 'Other video',
                'alt_text' => 'Other',
                'mime_type' => 'video/mp4',
            ]);
        });

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha-media-reset/sites/{$site->id}/media?search=Needle&mime_type=image&per_page=12")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.remote_id', 701);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha-media-reset/sites/{$site->id}/media?page=1&per_page=24")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('per_page', 24)
            ->assertJsonPath('current_page', 1);
    }

    public function test_media_reset_read_fails_closed_for_foreign_tenant_and_foreign_site(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha-reset-boundary', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha, 'Alpha Reset Boundary');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta-reset-boundary', ['tenant.view', 'content.view']);
        $betaSite = $this->site($beta, 'Beta Reset Boundary');

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta-reset-boundary/sites/{$betaSite->id}/media?page=1&per_page=24")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha-reset-boundary/sites/{$betaSite->id}/media?page=1&per_page=24")
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/alpha-reset-boundary/sites/{$alphaSite->id}/media?page=1&per_page=24")
            ->assertOk();
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
        $role = Role::query()->create(['name' => "global-media-reset-{$slug}-{$user->id}"]);

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
