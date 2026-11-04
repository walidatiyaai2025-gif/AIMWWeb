<?php

namespace Tests\Feature;

use App\Platform\VerifiedMysqlBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class VerifiedMysqlBackupServiceTest extends TestCase
{
    public function test_innodb_snapshot_is_encrypted_authenticated_and_restorable_into_fresh_schema(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('A disposable MySQL service is required for encrypted recovery acceptance.');
        }

        Artisan::call('migrate', ['--force' => true]);
        $pdo = DB::connection()->getPdo();
        $recoveryDatabase = 'aiwm_recovery_'.bin2hex(random_bytes(5));
        $created = false;
        $service = app(VerifiedMysqlBackupService::class);

        try {
            $pdo->exec('CREATE DATABASE '.$this->identifier($recoveryDatabase).' CHARACTER SET utf8mb4');
            $created = true;

            $tenantSlug = 'snapshot-'.bin2hex(random_bytes(5));
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Backup Data Tenant', 'slug' => $tenantSlug,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scoped_settings')->insert([
                'tenant_id' => $tenantId, 'scope' => 'tenant',
                'key' => 'backup.test', 'value' => json_encode(['preserved' => true]),
                'is_secret' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $sourceUsers = (int) DB::table('users')->count();
            $backup = $service->backupNow(1);
            $this->assertTrue($backup['verified']);
            $this->assertGreaterThan(1, $backup['tables']);
            $this->assertSame($backup['sha256'], $service->verify($backup['id'])['sha256']);

            $settings = config('database.connections.mysql');
            $target = new PDO(
                'mysql:host='.$settings['host'].';port='.($settings['port'] ?? '3306').
                    ';dbname='.$recoveryDatabase.';charset=utf8mb4',
                (string) $settings['username'],
                (string) $settings['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $service->restoreToEmptyDatabase($backup['id'], $target);
            $this->assertSame($sourceUsers, (int) $target->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $restored = $target->query('SELECT value FROM scoped_settings WHERE `key`="backup.test"')->fetchColumn();
            $this->assertSame(['preserved' => true], json_decode((string) $restored, true));
            $this->assertSame(1, (int) $target->query(
                "SELECT COUNT(*) FROM tenants WHERE slug=".$target->quote($tenantSlug)
            )->fetchColumn());

            try {
                $service->restoreToEmptyDatabase($backup['id'], $target);
                $this->fail('Recovery must refuse a database that is not empty.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('empty isolated MySQL', $e->getMessage());
            }

            $snapshot = storage_path('app/system-updates/backups/mysql-'.$backup['id'].'/snapshot.enc');
            $this->assertStringNotContainsString('CREATE TABLE', (string) file_get_contents($snapshot));
            file_put_contents($snapshot, 'tampered', FILE_APPEND);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('checksum verification failed');
            $service->verify($backup['id']);
        } finally {
            if ($created) {
                $pdo->exec('DROP DATABASE IF EXISTS '.$this->identifier($recoveryDatabase));
            }
        }
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }
}
