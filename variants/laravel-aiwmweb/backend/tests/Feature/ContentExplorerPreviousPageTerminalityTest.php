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
        $this->assertStringContainsString('previousContentExplorerPage(page)', $control);
        $this->assertStringContainsString('if (page <= 1) return;', $control);
        $this->assertStringContainsString('onPage(previousContentExplorerPage(page))', $control);
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
