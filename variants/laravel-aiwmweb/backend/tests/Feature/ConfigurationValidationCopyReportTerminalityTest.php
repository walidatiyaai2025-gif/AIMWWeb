<?php

namespace Tests\Feature;

use App\Http\Controllers\ConfigurationValidationController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ConfigurationValidationCopyReportTerminalityTest extends TestCase
{
    // Exact-head CI anchor after official CopyReportAsync parity materialization.
    // Exact-head CI anchor after official RunValidation parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-39BB044AF2';

    private const RUN_VALIDATION_OPERATION_ID = 'AIMW-BILL-EE0BEAAC55';

    public function test_report_api_is_tenant_scoped_permission_guarded_and_canonical(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/configuration-validation/report', 'GET'));
        $this->assertSame(ConfigurationValidationController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_run_validation_is_a_canonical_tenant_scoped_post_command(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/configuration-validation/run', 'POST'));
        $this->assertSame(ConfigurationValidationController::class.'@run', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::RUN_VALIDATION_OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_run_validation_returns_authoritative_sanitized_result_and_fails_closed_across_tenants(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $response = $this->actingAs($user)
            ->postJson('/tenants/alpha/configuration-validation/run')
            ->assertOk()
            ->assertJsonPath('operation_id', self::RUN_VALIDATION_OPERATION_ID)
            ->assertJsonStructure(['checked_at_utc', 'critical_count', 'warning_count', 'items']);

        $json = $response->getContent();
        $this->assertStringNotContainsString(base_path(), $json);
        $this->assertStringNotContainsString(storage_path(), $json);
        $this->assertStringNotContainsString((string) config('app.key'), $json);

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->postJson('/tenants/limited/configuration-validation/run')->assertForbidden();

        $foreign = User::factory()->create();
        $this->membership($foreign, 'beta', ['settings.manage', 'tenant.view']);
        $this->actingAs($foreign)->postJson('/tenants/alpha/configuration-validation/run')->assertNotFound();
    }

    public function test_authorized_user_receives_sanitized_bounded_report(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $response = $this->actingAs($user)
            ->getJson('/tenants/alpha/configuration-validation/report')
            ->assertOk()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonStructure(['checked_at_utc', 'critical_count', 'warning_count', 'items']);

        $json = $response->getContent();
        $this->assertStringNotContainsString(base_path(), $json);
        $this->assertStringNotContainsString(storage_path(), $json);
        $this->assertStringNotContainsString((string) config('app.key'), $json);
    }

    public function test_guest_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/configuration-validation/report')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['tenant.view']);
        $this->actingAs($limited)->getJson('/tenants/alpha/configuration-validation/report')->assertForbidden();

        $allowed = User::factory()->create();
        $this->membership($allowed, 'alpha2', ['settings.manage', 'tenant.view']);
        $this->actingAs($allowed)->getJson('/tenants/foreign/configuration-validation/report')->assertNotFound();
    }

    public function test_backup_storage_diagnostic_uses_real_local_disk_without_creating_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $report = '/tenants/alpha/configuration-validation/report';
        $pending = $this->actingAs($user)->getJson($report)->assertOk();
        $backup = collect($pending->json('items'))->firstWhere('key', 'backups');
        $this->assertSame('warning', $backup['status']);
        $this->assertSame(0, $pending->json('critical_count'));
        $this->assertFalse(Storage::disk('local')->exists('backups'));
        $this->assertStringNotContainsString(storage_path(), $pending->getContent());

        Storage::disk('local')->makeDirectory('backups');
        $ready = $this->actingAs($user)->getJson($report)->assertOk();
        $this->assertSame(
            'valid',
            collect($ready->json('items'))->firstWhere('key', 'backups')['status'],
        );
        $this->assertSame(0, $ready->json('critical_count'));

        Storage::disk('local')->deleteDirectory('backups');
        Storage::disk('local')->put('backups', 'not-a-directory');
        $invalid = $this->actingAs($user)->getJson($report)->assertOk();
        $this->assertSame(
            'error',
            collect($invalid->json('items'))->firstWhere('key', 'backups')['status'],
        );
        $this->assertGreaterThanOrEqual(1, $invalid->json('critical_count'));
        $this->assertStringNotContainsString(storage_path(), $invalid->getContent());
    }

    public function test_symlinked_backup_disk_root_is_rejected_without_mutating_storage(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $realRoot = rtrim(Storage::disk('local')->path(''), '/\\');
        $link = dirname($realRoot).'/aiwm-backup-root-link-'.bin2hex(random_bytes(5));
        $originalRoot = config('filesystems.disks.local.root');

        if (! function_exists('symlink') || ! @symlink($realRoot, $link)) {
            $this->markTestSkipped('Creating a disposable symlink requires filesystem support.');
        }

        try {
            config()->set('filesystems.disks.local.root', $link);
            Storage::forgetDisk('local');

            $response = $this->actingAs($user)
                ->getJson('/tenants/alpha/configuration-validation/report')
                ->assertOk();

            $backup = collect($response->json('items'))->firstWhere('key', 'backups');
            $this->assertSame('error', $backup['status']);
            $this->assertGreaterThanOrEqual(1, $response->json('critical_count'));
            $this->assertStringNotContainsString($link, $response->getContent());
            $this->assertStringNotContainsString($realRoot, $response->getContent());
            $this->assertStringNotContainsString((string) config('app.key'), $response->getContent());
            $this->assertFalse(file_exists($realRoot.'/backups'));
            $this->assertDatabaseCount('backup_archives', 0);
        } finally {
            config()->set('filesystems.disks.local.root', $originalRoot);
            Storage::forgetDisk('local');
            @unlink($link);
        }
    }

    public function test_real_backup_post_provisions_the_same_verified_storage_target_reported_by_validation(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->membership($admin, 'alpha', ['settings.manage', 'tenant.view']);

        $report = '/tenants/alpha/configuration-validation/report';
        $before = $this->actingAs($admin)->getJson($report)->assertOk();
        $this->assertSame(
            'warning',
            collect($before->json('items'))->firstWhere('key', 'backups')['status'],
        );
        $this->assertFalse(Storage::disk('local')->exists('backups'));

        $created = $this->actingAs($admin)
            ->postJson('/api/tenants/alpha/backups', ['note' => 'Validation integration rehearsal'])
            ->assertCreated()
            ->assertJsonPath('data.verified', true);

        $path = (string) $created->json('data.path');
        $this->assertStringStartsWith('backups/tenant-', $path);
        Storage::disk('local')->assertExists($path);
        $stored = Storage::disk('local')->get($path);
        $this->assertSame(hash('sha256', $stored), $created->json('data.sha256'));
        $decoded = json_decode((string) gzdecode($stored), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('AIMW-BILL-DDA412F087', $decoded['operation_id']);
        $this->assertDatabaseHas('backup_archives', [
            'path' => $path,
            'sha256' => hash('sha256', $stored),
        ]);

        $after = $this->actingAs($admin)->getJson($report)->assertOk();
        $this->assertSame(
            'valid',
            collect($after->json('items'))->firstWhere('key', 'backups')['status'],
        );
        $this->assertSame(0, $after->json('critical_count'));
        $this->assertStringNotContainsString(storage_path(), $after->getContent());
        $this->assertStringNotContainsString((string) config('app.key'), $after->getContent());
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'config-validation-'.$slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership;
    }
}
