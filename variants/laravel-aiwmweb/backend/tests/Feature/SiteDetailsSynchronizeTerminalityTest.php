<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Jobs\SyncSiteJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SyncRun;
use App\Sync\SyncDiagnosticFormatter;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteDetailsSynchronizeTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-94755B17E4';

    public function test_source_control_and_laravel_route_bind_the_exact_site_details_synchronize_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteDetails.razor'));
        $frontend = (string) file_get_contents(resource_path('js/site-details-synchronize-control.tsx'));

        $this->assertStringContainsString('@onclick="SynchronizeAsync"', $source);
        $this->assertStringContainsString('private async Task SynchronizeAsync()', $source);
        $this->assertStringContainsString('SyncService.SynchronizeAsync(Id, _syncCts.Token)', $source);

        $this->assertSame(self::OPERATION_ID, DemoController::SITE_DETAILS_SYNC_OPERATION_ID);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("'Idempotency-Key'", $frontend);
        $this->assertStringContainsString('authoritative Site Details', $frontend);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/sync', 'POST'));
        $this->assertSame(DemoController::class.'@sync', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_sync_fails_closed_for_permission_and_foreign_site_then_dispatches_once_for_the_owned_site(): void
    {
        Queue::fake();

        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($alpha);
        $alphaSite = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $reader = User::factory()->create();
        $this->membershipExisting($reader, $alpha, ['tenant.view', 'sites.view'], 'reader');

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta', 'url' => 'https://beta.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $this->actingAs($reader)
            ->withHeader('Idempotency-Key', 'site-details-reader')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertForbidden();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'site-details-foreign')
            ->postJson("/api/tenants/alpha/sites/{$betaSite->id}/sync")
            ->assertNotFound();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'site-details-owned')
            ->postJson("/api/tenants/alpha/sites/{$alphaSite->id}/sync")
            ->assertAccepted()
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(SyncSiteJob::class, 1);
    }

    public function test_authorized_site_manager_sees_sanitized_sync_failure_while_reader_sees_generic_error(): void
    {
        $manager = User::factory()->create();
        $tenant = $this->membership($manager, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $this->activate($tenant);
        $site = Site::query()->create(['name' => 'Alpha', 'url' => 'https://alpha.test', 'status' => 'active']);
        $run = SyncRun::query()->create([
            'site_id' => $site->id,
            'status' => 'failed',
            'failure' => 'RuntimeException: Connector is revoked. password=hidden-value Bearer secret-bearer-123',
            'completed_at' => now(),
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)->getJson("/api/tenants/alpha/sync-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('diagnostic_code', 'WORDPRESS_SYNC_FAILED')
            ->assertJsonPath('failure', 'RuntimeException: Connector is revoked. password=[REDACTED] Bearer [REDACTED]')
            ->assertDontSee('hidden-value')
            ->assertDontSee('secret-bearer-123');

        $reader = User::factory()->create();
        $this->membershipExisting($reader, $tenant, ['tenant.view', 'sites.view'], 'reader');
        $this->actingAs($reader)->getJson("/api/tenants/alpha/sync-runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('failure', 'Synchronization failed.')
            ->assertDontSee('Connector is revoked')
            ->assertDontSee('hidden-value');
    }

    public function test_failure_diagnostics_redact_urls_credentials_and_sql_payloads(): void
    {
        $diagnostic = SyncDiagnosticFormatter::exception(new \RuntimeException(
            'Connection failed https://user:secret@wp.test/path?token=abc password=abc (SQL: select * from users where password="abc")'
        ));

        $this->assertStringContainsString('RuntimeException: Connection failed', $diagnostic);
        $this->assertStringContainsString('[URL REDACTED]', $diagnostic);
        $this->assertStringNotContainsString('user:secret', $diagnostic);
        $this->assertStringNotContainsString('password=abc', $diagnostic);
        $this->assertStringNotContainsString('select * from users', $diagnostic);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->membershipExisting($user, $tenant, $permissions, $slug);

        return $tenant;
    }

    private function membershipExisting(User $user, Tenant $tenant, array $permissions, string $roleName): void
    {
        $this->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-details-sync-{$roleName}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
