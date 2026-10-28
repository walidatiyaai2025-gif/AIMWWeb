<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
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

final class GlobalSynchronizationConflictEditorLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-0239B0E6A3';

    public function test_source_conflict_editor_link_uses_the_real_authorized_laravel_editor_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalSynchronizationWorkspace.razor'));
        $frontend = (string) file_get_contents(resource_path('js/global-synchronization-review-conflicts-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('Href="@EditorUrl(conflict)"', $source);
        $this->assertStringContainsString('conflict.Kind == SyncConflictKind.RemoteUpdated', $source);
        $this->assertStringContainsString('/content/{conflict.ContentType}/{conflict.WordPressId}/edit', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-canonical-operation', $frontend);
        $this->assertStringContainsString('globalSynchronizationConflictEditorHref', $frontend);
        $this->assertStringContainsString('GlobalSynchronizationReviewConflictsControl context={context}', $app);

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7/content/post/101/edit', 'GET'));
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showContentEditor',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('content.view', $route->defaults['workspace_permissions'] ?? null);
    }

    public function test_target_editor_route_denies_foreign_tenant_site_and_unauthorized_actor(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['content.view']);
        $alphaSite = $this->site($alpha, 'Alpha');

        $limited = User::factory()->create();
        $this->membershipOnTenant($limited, $alpha, []);

        $other = User::factory()->create();
        $beta = $this->membership($other, 'beta', ['content.view']);
        $betaSite = $this->site($beta, 'Beta');

        $this->actingAs($limited)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/content/post/101/edit")
            ->assertForbidden();

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/beta/sites/{$betaSite->id}/content/post/202/edit")
            ->assertNotFound();

        $this->actingAs($owner)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/content/post/0/edit")
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipOnTenant($user, $tenant, $permissions);

        return $tenant;
    }

    private function membershipOnTenant(User $user, Tenant $tenant, array $permissions): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'global-conflict-editor-'.$tenant->slug.'-'.$user->id]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower($name).'.example.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
