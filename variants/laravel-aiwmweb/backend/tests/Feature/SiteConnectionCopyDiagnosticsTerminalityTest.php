<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteDiagnosticsController;
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

final class SiteConnectionCopyDiagnosticsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-D4E1DA62D9';

    public function test_source_and_laravel_frontend_bind_exact_copy_diagnostics_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteConnectionCenter.razor'));
        $this->assertStringContainsString('@page "/sites/{Id:guid}/connection"', $source);
        $this->assertStringContainsString('@page "/sites/{Id:guid}/operations"', $source);
        $this->assertStringContainsString('@onclick="CopyDiagnosticsAsync"', $source);
        $this->assertStringContainsString('AI WordPress Manager - Site Diagnostics', $source);
        $this->assertStringContainsString('navigator.clipboard.writeText', $source);
        $this->assertStringContainsString('_history.Take(20)', $source);

        $frontend = (string) file_get_contents(resource_path('js/site-connection-center-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('formatSiteDiagnosticsReport', $frontend);
        $this->assertStringContainsString('navigator.clipboard', $frontend);
        $this->assertStringContainsString('history.slice(0, 20)', $frontend);
        $this->assertStringNotContainsString('provider_secret', $frontend);
        $this->assertStringNotContainsString('application_password', $frontend);

        $connection = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/connection', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@status', ltrim($connection->getActionName(), '\\'));
        $this->assertContains('auth', $connection->gatherMiddleware());
        $this->assertContains('tenant.context', $connection->gatherMiddleware());

        $operations = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/operations', 'GET'));
        $this->assertSame(SiteDiagnosticsController::class.'@operations', ltrim($operations->getActionName(), '\\'));
        $this->assertContains('auth', $operations->gatherMiddleware());
        $this->assertContains('tenant.context', $operations->gatherMiddleware());
    }
    public function test_copy_diagnostics_authoritative_reads_fail_closed_for_foreign_tenant_site(): void
    {
        $reader = User::factory()->create();
        $alpha = $this->membership($reader, 'alpha', ['tenant.view', 'sites.view']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Site', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($reader)
            ->getJson("/api/tenants/alpha/sites/{$alphaSite->id}/connection")
            ->assertOk()
            ->assertJsonPath('site.id', $alphaSite->id);

        $this->actingAs($reader)
            ->getJson("/api/tenants/alpha/sites/{$betaSite->id}/connection")
            ->assertNotFound();

        $this->actingAs($reader)
            ->getJson("/api/tenants/alpha/sites/{$betaSite->id}/operations?take=20")
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-reader-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }

}
