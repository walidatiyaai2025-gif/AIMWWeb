<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationsMaintenanceCleanupController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteOperationsMaintenanceCleanupTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-DA1A53D8A1';

    public function test_exact_critical_canonical_cleanup_operation_is_terminalized(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/operations/maintenance | /site-operations/maintenance', $operation['route_screen']);
        $this->assertStringContainsString('RunCleanupAsync', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor',
            $operation['current_source'],
        );
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('critical', $operation['risk']);
    }

    public function test_cleanup_route_and_rendered_control_are_web_auth_tenant_csrf_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/site-operations/maintenance/cleanup', 'POST'),
        );

        $this->assertSame(
            SiteOperationsMaintenanceCleanupController::class,
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-operations-maintenance.cleanup', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(
            'execution.view,operations.manage',
            $route->defaults['workspace_permissions'] ?? null,
        );
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['execution.view', 'operations.manage']);
        $this->withoutVite();

        $response = $this->actingAs($user)->get('/tenants/alpha/site-operations/maintenance');

        $response
            ->assertOk()
            ->assertSee('data-maintenance-cleanup-form', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="older_than_days"', false)
            ->assertSee('name="keep_latest"', false)
            ->assertSee('name="confirmation"', false)
            ->assertSee('window.confirm', false)
            ->assertDontSee('name="tenant_id"', false)
            ->assertDontSee('name="site_id"', false)
            ->assertDontSee('name="history_id"', false)
            ->assertDontSee('name="actor_user_id"', false);
    }

    public function test_cleanup_control_is_hidden_without_manage_permission_and_server_authorization_still_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'limited', ['execution.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/limited/site-operations/maintenance')
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID);

        $this->actingAs($user)
            ->postJson('/tenants/limited/site-operations/maintenance/cleanup', [
                'older_than_days' => 30,
                'keep_latest' => 50,
                'confirmation' => 'CLEANUP',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('site_operation_histories', 0);
    }

    public function test_guest_foreign_tenant_and_invalid_policy_or_confirmation_fail_before_any_delete(): void
    {
        $this->post('/tenants/alpha/site-operations/maintenance/cleanup', [
            'older_than_days' => 30,
            'keep_latest' => 50,
            'confirmation' => 'CLEANUP',
        ])->assertRedirect('/login');

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['execution.view', 'operations.manage']);
        $this->seedHistory($alpha, 'Alpha cleanup site', 52);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['execution.view', 'operations.manage']);
        $this->seedHistory($beta, 'Beta cleanup site', 3);

        $this->actingAs($alphaUser)
            ->postJson('/tenants/beta/site-operations/maintenance/cleanup', [
                'older_than_days' => 30,
                'keep_latest' => 50,
                'confirmation' => 'CLEANUP',
            ])
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->postJson('/tenants/alpha/site-operations/maintenance/cleanup', [
                'older_than_days' => 31,
                'keep_latest' => 50,
                'confirmation' => 'CLEANUP',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['older_than_days']);

        $this->actingAs($alphaUser)
            ->postJson('/tenants/alpha/site-operations/maintenance/cleanup', [
                'older_than_days' => 30,
                'keep_latest' => 51,
                'confirmation' => 'CLEANUP',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keep_latest']);

        $this->actingAs($alphaUser)
            ->postJson('/tenants/alpha/site-operations/maintenance/cleanup', [
                'older_than_days' => 30,
                'keep_latest' => 50,
                'confirmation' => 'cleanup',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation']);

        $this->assertTenantHistoryCount($alpha->tenant_id, 52);
        $this->assertTenantHistoryCount($beta->tenant_id, 3);
    }

    public function test_real_cleanup_deletes_only_active_tenant_rows_and_reports_success_after_authoritative_reread(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['execution.view', 'operations.manage']);
        $this->seedHistory($alpha, 'Alpha cleanup site', 52);

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['execution.view', 'operations.manage']);
        $this->seedHistory($beta, 'Beta cleanup site', 3);

        $foreignActor = User::factory()->create();

        $response = $this->actingAs($alphaUser)
            ->post('/tenants/alpha/site-operations/maintenance/cleanup', [
                'older_than_days' => 30,
                'keep_latest' => 50,
                'confirmation' => '  CLEANUP  ',
                'tenant_id' => $beta->tenant_id,
                'actor_user_id' => $foreignActor->id,
                'site_id' => 999999,
                'history_id' => 999999,
                'secret' => 'must-not-be-echoed',
            ]);

        $response
            ->assertRedirect('/tenants/alpha/site-operations/maintenance')
            ->assertSessionHas(
                'status',
                'Removed 2 operation-history records; 50 records remain.',
            )
            ->assertDontSee('must-not-be-echoed');

        $this->assertTenantHistoryCount($alpha->tenant_id, 50);
        $this->assertTenantHistoryCount($beta->tenant_id, 3);

        $context = app(TenantContext::class);
        $context->activate($alpha->tenant, $alpha);
        $storage = app(SiteOperationHistoryService::class)->getStorageInfo();
        $preview = app(SiteOperationHistoryService::class)->previewCleanup(30, 50);
        $context->forget();

        $this->assertSame(50, (int) $storage['record_count']);
        $this->assertSame(50, (int) $preview['total_count']);
        $this->assertSame(0, (int) $preview['removable_count']);
    }

    public function test_retry_after_success_fails_closed_without_additional_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'retry', ['execution.view', 'operations.manage']);
        $this->seedHistory($membership, 'Retry cleanup site', 52);

        $payload = [
            'older_than_days' => 30,
            'keep_latest' => 50,
            'confirmation' => 'CLEANUP',
        ];

        $this->actingAs($user)
            ->post('/tenants/retry/site-operations/maintenance/cleanup', $payload)
            ->assertRedirect('/tenants/retry/site-operations/maintenance');

        $this->assertTenantHistoryCount($membership->tenant_id, 50);

        $this->actingAs($user)
            ->postJson('/tenants/retry/site-operations/maintenance/cleanup', $payload)
            ->assertConflict();

        $this->assertTenantHistoryCount($membership->tenant_id, 50);
    }

    public function test_source_transaction_and_failure_contracts_preserve_confirmation_reread_retry_and_secret_boundaries(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsMaintenance.razor'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SiteOperationsMaintenanceCleanupController.php'),
        );
        $service = (string) file_get_contents(app_path('Sites/SiteOperationHistoryService.php'));
        $view = (string) file_get_contents(resource_path('views/site-operations-maintenance.blade.php'));
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('RunCleanupAsync', $source);
        $this->assertStringContainsString('string.Equals(_confirmation.Trim(), "CLEANUP"', $source);
        $this->assertStringContainsString('JS.InvokeAsync<bool>', $source);
        $this->assertStringContainsString('Maintenance.CleanupAsync', $source);
        $this->assertStringContainsString('ApplySnapshot(await Maintenance.GetSnapshotAsync', $source);

        $this->assertStringContainsString("authorize('execution.view')", $controller);
        $this->assertStringContainsString("authorize('operations.manage')", $controller);
        $this->assertStringContainsString('getAuthIdentifier()', $controller);
        $this->assertStringContainsString('membership->user_id', $controller);
        $this->assertStringContainsString('previewCleanup', $controller);
        $this->assertGreaterThanOrEqual(2, substr_count($controller, 'getStorageInfo()'));
        $this->assertStringContainsString('Cleanup persistence could not be verified by authoritative reread.', $controller);

        $this->assertStringContainsString('return DB::transaction', $service);
        $this->assertStringContainsString('}, 3);', $service);
        $this->assertStringContainsString('whereNotIn', $service);

        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString('window.confirm', $view);
        $this->assertStringNotContainsString('password', strtolower($view));
        $this->assertStringNotContainsString('api_key', strtolower($view));
        $this->assertStringNotContainsString('client_secret', strtolower($view));

        $this->assertStringContainsString(
            "validateCsrfTokens(except: ['api/v1/billing/webhooks/paypal'])",
            $bootstrap,
        );
        $this->assertStringNotContainsString('dispatch(', $controller);
        $this->assertStringNotContainsString('queue(', strtolower($controller));
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
        $role = Role::query()->create(['name' => "maintenance-cleanup-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function seedHistory(TenantMembership $membership, string $siteName, int $count): void
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $siteName,
            'url' => 'https://'.str($siteName)->slug().'.example.test',
            'status' => 'active',
        ]);

        $history = app(SiteOperationHistoryService::class);
        $startedAt = now()->subDays(90);

        for ($index = 0; $index < $count; $index++) {
            $history->record(
                $site->id,
                "cleanup.fixture.{$index}",
                true,
                'Completed',
                startedAt: $startedAt->copy()->subMinutes($index),
            );
        }

        $context->forget();
    }

    private function assertTenantHistoryCount(int $tenantId, int $expected): void
    {
        $this->assertSame(
            $expected,
            SiteOperationHistory::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->count(),
        );
    }
}
