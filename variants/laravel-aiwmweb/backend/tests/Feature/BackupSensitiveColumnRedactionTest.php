<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackupSensitiveColumnRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_backup_never_exposes_authorization_cookie_access_key_or_passwd_values(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);

        app(TenantContext::class)->activate($tenant);
        TenantMembership::query()->create([
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        Schema::create('backup_secret_fixture_rows', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('authorization_header');
            $table->string('cookie_value');
            $table->string('access_key');
            $table->string('passwd');
            $table->string('notes');
        });

        $secrets = [
            'authorization_header' => 'Bearer never-export-authorization',
            'cookie_value' => 'session=never-export-cookie',
            'access_key' => 'never-export-access-key',
            'passwd' => 'never-export-passwd',
        ];

        try {
            DB::table('backup_secret_fixture_rows')->insert([
                'tenant_id' => $tenant->id,
                'authorization_header' => $secrets['authorization_header'],
                'cookie_value' => $secrets['cookie_value'],
                'access_key' => $secrets['access_key'],
                'passwd' => $secrets['passwd'],
                'notes' => 'safe-business-value',
            ]);

            $response = $this->actingAs($admin)
                ->postJson('/api/tenants/alpha/backups', ['note' => 'Redaction regression'])
                ->assertCreated()
                ->assertJsonPath('data.verified', true);

            $path = (string) $response->json('data.path');
            Storage::disk('local')->assertExists($path);
            $stored = (string) Storage::disk('local')->get($path);
            $json = gzdecode($stored);
            $this->assertNotFalse($json);

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }

            $archive = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            // MySQL and SQLite schema introspection can return either a bare
            // table name or a database/schema-qualified table identifier.
            $matching = array_filter(
                array_keys($archive['tables']),
                static fn (string $name): bool => $name === 'backup_secret_fixture_rows'
                    || str_ends_with($name, '.backup_secret_fixture_rows'),
            );
            $this->assertCount(1, $matching, 'The actual tenant backup must include the fixture table.');
            $rows = $archive['tables'][array_values($matching)[0]];
            $this->assertCount(1, $rows);
            $this->assertSame($tenant->id, $rows[0]['tenant_id']);
            $this->assertSame('safe-business-value', $rows[0]['notes']);

            foreach (array_keys($secrets) as $column) {
                $this->assertSame(['redacted' => true], $rows[0][$column]);
            }

            $this->assertDatabaseHas('backup_archives', [
                'tenant_id' => $tenant->id,
                'path' => $path,
                'sha256' => hash('sha256', $stored),
            ]);
        } finally {
            Schema::dropIfExists('backup_secret_fixture_rows');
        }
    }
}
