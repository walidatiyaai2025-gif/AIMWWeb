<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupCreateController;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupCreateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-DDA412F087';

    public function test_route_is_canonical_tenant_scoped_and_platform_admin_only(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/backups', 'POST'));

        $this->assertSame(BackupCreateController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertContains('platform.admin', $route->gatherMiddleware());
    }

    public function test_admin_creates_verified_tenant_archive_and_authoritative_metadata(): void
    {
        Storage::fake('local');
        [$admin, $tenant] = $this->member('alpha', true);

        $response = $this->actingAs($admin)
            ->postJson('/api/tenants/alpha/backups', ['note' => 'Before upgrade'])
            ->assertCreated()
            ->assertJsonPath('data.operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.protected_secret_recovery', false);

        $path = (string) $response->json('data.path');
        Storage::disk('local')->assertExists($path);
        $stored = Storage::disk('local')->get($path);
        $decoded = json_decode((string) gzdecode($stored), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($tenant->id, $decoded['tenant_id']);
        $this->assertSame(self::OPERATION_ID, $decoded['operation_id']);
        $this->assertFalse($decoded['protected_secret_recovery']);
        $this->assertNull($decoded['wrapped_data_key']);
        $this->assertSame('Before upgrade', $decoded['note']);
        $this->assertDatabaseHas('backup_archives', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $admin->id,
            'operation_id' => self::OPERATION_ID,
            'path' => $path,
            'sha256' => hash('sha256', $stored),
            'protected_secret_recovery' => false,
        ]);
    }

    public function test_recovery_secret_is_verified_wrapped_and_never_persisted_in_plaintext(): void
    {
        Storage::fake('local');
        [$admin, $tenant] = $this->member('alpha', true);
        $secret = 'correct-horse-battery-staple-2026';

        $response = $this->actingAs($admin)
            ->postJson('/api/tenants/alpha/backups', [
                'note' => 'Protected backup',
                'recovery_secret' => $secret,
                'recovery_secret_confirmation' => $secret,
            ])
            ->assertCreated()
            ->assertJsonPath('data.protected_secret_recovery', true)
            ->assertJsonMissingPath('data.wrapped_data_key');

        $path = (string) $response->json('data.path');
        $stored = Storage::disk('local')->get($path);
        $this->assertStringNotContainsString($secret, $stored);
        $decoded = json_decode((string) gzdecode($stored), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($decoded['protected_secret_recovery']);
        $this->assertIsArray($decoded['wrapped_data_key']);
        $this->assertSame('PBKDF2-SHA256', $decoded['wrapped_data_key']['kdf']);
        $this->assertSame('AES-256-GCM', $decoded['wrapped_data_key']['alg']);

        $row = DB::table('backup_archives')->where('tenant_id', $tenant->id)->sole();
        $this->assertTrue((bool) $row->protected_secret_recovery);
        $this->assertStringNotContainsString($secret, json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    public function test_guest_non_admin_mismatch_and_caller_owned_fields_fail_closed(): void
    {
        Storage::fake('local');
        $this->postJson('/api/tenants/alpha/backups', [])->assertUnauthorized();

        [$member] = $this->member('alpha', false);
        $this->actingAs($member)->postJson('/api/tenants/alpha/backups', [])->assertForbidden();

        [$admin] = $this->member('beta', true);
        $this->actingAs($admin)->postJson('/api/tenants/alpha/backups', [])->assertNotFound();

        [$alphaAdmin] = $this->member('alpha', true);
        $this->actingAs($alphaAdmin)->postJson('/api/tenants/alpha/backups', [
            'tenant_id' => 999,
            'actor_user_id' => 999,
            'path' => 'attacker/path',
            'sha256' => str_repeat('0', 64),
        ])->assertUnprocessable();

        $this->actingAs($alphaAdmin)->postJson('/api/tenants/alpha/backups', [
            'recovery_secret' => 'abcdefghijklmnop',
            'recovery_secret_confirmation' => 'different-secret-123',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('backup_archives', 0);
    }

    private function member(string $slug, bool $platformAdmin): array
    {
        $user = User::factory()->create(['platform_admin' => $platformAdmin]);
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return [$user, $tenant];
    }
}
