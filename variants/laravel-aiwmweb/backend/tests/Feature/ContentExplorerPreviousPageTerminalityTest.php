<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentExplorerReadController;
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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentExplorerPreviousPageTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-A31317B378';

    public function test_source_contract_decrements_one_page_and_rebuilds_without_mutation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'));

        $this->assertStringContainsString('@onclick="PreviousPage"', $source);
        $this->assertStringContainsString('private void PreviousPage()', $source);
        $this->assertStringContainsString('if (_page <= 1) return;', $source);
        $this->assertStringContainsString('_page--;', $source);
        $this->assertStringContainsString('RebuildPage();', $source);
    }

    public function test_laravel_runtime_binds_previous_page_to_authoritative_explorer_pagination(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-explorer-previous-page-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('function previousContentExplorerPage(page: number)', $control);
        $this->assertStringContainsString('if (safePage <= 1) return;', $control);
        $this->assertStringContainsString('onPage(previousContentExplorerPage(safePage))', $control);
        $this->assertStringContainsString("route.key === 'explorer'", $pages);
        $this->assertStringContainsString('<ContentExplorerPagination page={collection.page} lastPage={collection.lastPage} onPage={setPage}', $pages);
        $this->assertStringContainsString("url.searchParams.set('page', String(page))", $pages);

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('AIMW-CONT-', str_replace(self::OPERATION_ID, '', $control));
    }

    public function test_authoritative_previous_page_read_is_permission_guarded_and_tenant_site_scoped(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'previous-alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Previous Alpha');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        for ($index = 1; $index <= 25; $index++) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => 1000 + $index,
                'type' => 'post',
                'status' => 'publish',
                'title' => 'Previous Alpha '.$index,
                'synced_at' => now()->subMinutes($index),
            ]);
        }
        $context->forget();

        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/previous-alpha/sites/'.$site->id.'/explorer', 'GET'),
        );
        $this->assertSame(ContentExplorerReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/previous-alpha/sites/'.$site->id.'/explorer?page=2&per_page=10')
            ->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/previous-alpha/sites/'.$site->id.'/explorer?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(10, 'data');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'previous-limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Previous Limited');
        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/previous-limited/sites/'.$limitedSite->id.'/explorer?page=1')
            ->assertForbidden();

        $foreignOwner = User::factory()->create();
        $foreignMembership = $this->membership($foreignOwner, 'previous-foreign', ['tenant.view', 'sites.view']);
        $foreignSite = $this->site($foreignMembership, 'Previous Foreign');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/previous-alpha/sites/'.$foreignSite->id.'/explorer?page=1')
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/previous-foreign/sites/'.$foreignSite->id.'/explorer?page=1')
            ->assertNotFound();
    }

    public function test_real_explorer_page_boundaries_and_deleted_last_page_recovery(): void
    {
        $user = User::factory()->create();
        $member = $this->membership($user, 'explorer-page-recovery', ['tenant.view', 'sites.view']);
        $site = $this->site($member, 'Explorer Recovery Main');
        $secondSite = $this->site($member, 'Explorer Recovery Other');

        $context = app(TenantContext::class);
        $context->activate($member->tenant, $member);
        for ($remoteId = 1001; $remoteId <= 1063; $remoteId++) {
            ContentItem::query()->create([
                'site_id' => $site->id,
                'remote_id' => $remoteId,
                'type' => 'post',
                'title' => 'Canonical post '.$remoteId,
                'slug' => 'canonical-'.$remoteId,
                'status' => 'publish',
                'remote_modified_at' => '2026-11-08 12:00:00',
            ]);
        }
        ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => 2001,
            'type' => 'page',
            'title' => 'Different content type',
            'status' => 'publish',
        ]);
        ContentItem::query()->create([
            'site_id' => $secondSite->id,
            'remote_id' => 9001,
            'type' => 'post',
            'title' => 'Second site only',
            'status' => 'publish',
        ]);
        $context->forget();

        $url = "/api/v1/tenants/explorer-page-recovery/sites/{$site->id}/explorer";
        foreach ([[1, 1063, 25], [2, 1038, 25], [3, 1013, 13], [1000000, 1013, 13]] as [$page, $firstId, $count]) {
            $this->actingAs($user)
                ->getJson($url."?type=post&status=publish&per_page=25&page={$page}")
                ->assertOk()
                ->assertJsonPath('total', 63)
                ->assertJsonPath('current_page', min($page, 3))
                ->assertJsonPath('last_page', 3)
                ->assertJsonCount($count, 'data')
                ->assertJsonPath('data.0.wordpress_id', $firstId)
                ->assertJsonPath('meta.totals.posts', 63)
                ->assertJsonPath('meta.totals.pages', 1);
        }

        // No fake totals or empty overflow pages after all filtered rows disappear.
        $this->actingAs($user)->getJson($url.'?search=not-matched&page=1000')
            ->assertOk()->assertJsonPath('total', 0)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson($url.'?type=post&per_page=500')
            ->assertOk()->assertJsonPath('total', 63)
            ->assertJsonCount(63, 'data'); // Legacy per_page > 100 is capped, not rejected.

        foreach (['page=0', 'page=-1', 'page=1000001', 'page=not-an-integer', 'page%5B%5D=2'] as $invalid) {
            $this->actingAs($user)->getJson($url.'?'.$invalid)->assertUnprocessable();
        }

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/explorer-page-recovery/sites/{$secondSite->id}/explorer?page=1")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.wordpress_id', 9001);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "previous-page-{$slug}-{$user->id}"]);
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
        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
                'status' => 'active',
                'connection_status' => 'connected',
            ]);
        } finally {
            $context->forget();
        }
    }
}
