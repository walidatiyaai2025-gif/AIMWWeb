<?php

namespace Tests\Feature;

use App\Models\User;
use App\Platform\SystemUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class SystemUpdateCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_update_routes_are_auth_and_platform_admin_only(): void
    {
        foreach ([
            ['GET', '/admin/system-updates'],
            ['POST', '/admin/system-updates/check'],
            ['POST', '/admin/system-updates/install'],
            ['POST', '/admin/system-updates/upload'],
            ['POST', '/admin/system-updates/verify'],
            ['POST', '/admin/system-updates/backup'],
        ] as [$method, $path]) {
            $route = Route::getRoutes()->match(Request::create($path, $method));
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('platform.admin', $route->gatherMiddleware());
            $this->assertContains('throttle:5,1', $route->gatherMiddleware());
            $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        }
    }

    public function test_guest_and_tenant_admin_cannot_access_update_routes(): void
    {
        // This test verifies AUTHORIZATION in isolation: twelve denial requests
        // would otherwise legitimately trip the five-per-minute rate limiter.
        // The separate route-contract assertion above still enforces throttle.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->get('/admin/system-updates')->assertRedirect('/login');
        $this->post('/admin/system-updates/check')->assertRedirect('/login');
        $this->post('/admin/system-updates/install', ['confirmation' => 'INSTALL'])->assertRedirect('/login');
        $this->post('/admin/system-updates/upload', ['confirmation' => 'INSTALL'])->assertRedirect('/login');
        $this->post('/admin/system-updates/verify')->assertRedirect('/login');
        $this->post('/admin/system-updates/backup', ['confirmation' => 'BACKUP'])->assertRedirect('/login');

        $user = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($user)->get('/admin/system-updates')->assertForbidden();
        $this->actingAs($user)->post('/admin/system-updates/check')->assertForbidden();
        $this->actingAs($user)->post('/admin/system-updates/install', ['confirmation' => 'INSTALL'])->assertForbidden();
        $this->actingAs($user)->post('/admin/system-updates/upload', ['confirmation' => 'INSTALL'])->assertForbidden();
        $this->actingAs($user)->post('/admin/system-updates/verify')->assertForbidden();
        $this->actingAs($user)->post('/admin/system-updates/backup', ['confirmation' => 'BACKUP'])->assertForbidden();
    }

    public function test_backup_now_requires_confirmation_and_never_exposes_database_keys(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $this->actingAs($admin)->get('/admin/system-updates')
            ->assertOk()
            ->assertSee('Backup Now')
            ->assertDontSee('AIWM_UPDATES_SECRET_KEY');

        $this->actingAs($admin)->post('/admin/system-updates/backup')
            ->assertSessionHasErrors('confirmation');
    }

    public function test_update_page_honestly_disables_installation_without_signing_configuration(): void
    {
        config()->set('system_updates.enabled', false);
        config()->set('system_updates.public_key', '');
        $admin = User::factory()->create(['platform_admin' => true]);

        $this->actingAs($admin)->get('/admin/system-updates')
            ->assertOk()
            ->assertSee('System updates')
            ->assertSee('Not configured')
            ->assertSee('disabled', false)
            ->assertSee('name="_token"', false)
            ->assertSee('AIWMWeb');
    }

    public function test_signed_stable_release_can_be_checked_without_installing_files(): void
    {
        $keys = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keys);
        $secret = sodium_crypto_sign_secretkey($keys);
        config()->set('system_updates.enabled', true);
        config()->set('system_updates.public_key', base64_encode($public));
        config()->set('system_updates.current_version', '0.0.0');

        $manifest = json_encode([
            'product' => 'LARAVEL_AIWMWEB',
            'version' => '9.9.9',
            'sha' => str_repeat('a', 40),
            'package' => 'AIWMWeb-Laravel-update.zip',
            'sha256' => str_repeat('b', 64),
            'files' => ['app/SignedExample.php' => str_repeat('c', 64)],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = base64_encode(sodium_crypto_sign_detached($manifest, $secret));
        $this->fakeRelease($manifest, $signature);
        $release = app(SystemUpdateService::class)->latest();

        $this->assertSame('9.9.9', $release['version']);
        $this->assertTrue($release['available']);
        $this->assertSame(str_repeat('a', 40), $release['sha']);
    }

    public function test_unsigned_or_forged_release_is_rejected_before_download(): void
    {
        $keys = sodium_crypto_sign_keypair();
        config()->set('system_updates.enabled', true);
        config()->set('system_updates.public_key', base64_encode(sodium_crypto_sign_publickey($keys)));

        $this->fakeRelease('{"version":"9.9.9"}', base64_encode(str_repeat('x', 64)));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature could not be verified');
        app(SystemUpdateService::class)->latest();
    }

    public function test_update_package_rejects_traversal_private_paths_and_public_front_controller(): void
    {
        $service = new SystemUpdateService;
        $method = new \ReflectionMethod($service, 'validatePath');
        foreach (['../.env', 'storage/logs/app.log', 'app/../../.env', 'public/index.php', 'public/.htaccess', 'app/.env', 'app\\x.php', 'database/migrations/new.php'] as $path) {
            try {
                $method->invoke($service, $path);
                $this->fail('Unsafe update path accepted: '.$path);
            } catch (RuntimeException) {
                // Expected rejection.
            }
        }
        foreach (['app/Http/Controllers/Safe.php', 'public/build/assets/app.js', 'vendor/composer/installed.json', 'vendor/symfony/http-foundation/Session/Storage/Handler/AbstractSessionHandler.php'] as $path) {
            $method->invoke($service, $path);
            $this->assertTrue(true);
        }
    }

    public function test_uploaded_signed_bundle_verifies_manifest_and_exact_inner_package_hash(): void
    {
        $keys = sodium_crypto_sign_keypair();
        config()->set('system_updates.enabled', true);
        config()->set('system_updates.public_key', base64_encode(sodium_crypto_sign_publickey($keys)));
        config()->set('system_updates.current_version', '0.0.0');

        $payload = tempnam(sys_get_temp_dir(), 'aiwm-payload-');
        $bundlePath = tempnam(sys_get_temp_dir(), 'aiwm-bundle-');
        $staged = tempnam(sys_get_temp_dir(), 'aiwm-stage-');

        try {
            $inner = new ZipArchive;
            $this->assertTrue($inner->open($payload, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
            $contents = "<?php\n// Update bundle verification fixture.\n";
            $inner->addFromString('app/SignedUpdateMarker.php', $contents);
            $this->assertTrue($inner->close());

            $manifest = json_encode([
                'product' => 'LARAVEL_AIWMWEB',
                'version' => '9.9.9',
                'sha' => str_repeat('a', 40),
                'package' => 'AIWMWeb-Laravel-update.zip',
                'sha256' => hash_file('sha256', $payload),
                'files' => ['app/SignedUpdateMarker.php' => hash('sha256', $contents)],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $bundle = new ZipArchive;
            $this->assertTrue($bundle->open($bundlePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
            $bundle->addFromString('AIWMWeb-Laravel-release.json', $manifest);
            $bundle->addFromString('AIWMWeb-Laravel-release.sig', base64_encode(
                sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($keys)),
            ));
            $bundle->addFile($payload, 'AIWMWeb-Laravel-update.zip');
            $this->assertTrue($bundle->close());

            $verified = (new \ReflectionMethod(SystemUpdateService::class, 'unpackUploadedBundle'))
                ->invoke(
                    app(SystemUpdateService::class),
                    new UploadedFile($bundlePath, 'signed-bundle.zip', 'application/zip', null, true),
                    $staged,
                );
            $preview = app(SystemUpdateService::class)->verifyUpload(
                new UploadedFile($bundlePath, 'signed-bundle.zip', 'application/zip', null, true),
            );
            $this->assertSame('9.9.9', $preview['version']);
            $this->assertSame(1, $preview['file_count']);
            $this->assertSame('9.9.9', $verified['version']);
            $this->assertSame(str_repeat('a', 40), $verified['sha']);
            $this->assertTrue($verified['available']);
            $this->assertSame(hash_file('sha256', $payload), hash_file('sha256', $staged));

            // Verify that a valid signed ZIP cannot change code when the database
            // snapshot prerequisite is unsupported (SQLite CI test connection).
            if (DB::connection()->getDriverName() === 'sqlite') {
                $target = base_path('app/SignedUpdateMarker.php');
                $originalContents = is_file($target) ? file_get_contents($target) : null;

                try {
                    app(SystemUpdateService::class)->install(
                        1,
                        new UploadedFile($bundlePath, 'signed-bundle.zip', 'application/zip', null, true),
                    );
                    $this->fail('A signed ZIP must not mutate code without verified MySQL backup.');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('requires mysql and sodium', $e->getMessage());
                }

                $this->assertSame($originalContents, is_file($target) ? file_get_contents($target) : null);
                $this->assertFileDoesNotExist(storage_path('app/system-updates/installed.json'));
            }

            $tampered = new ZipArchive;
            $this->assertTrue($tampered->open($bundlePath) === true);
            $tampered->addFromString('AIWMWeb-Laravel-release.sig', base64_encode(str_repeat('x', 64)));
            $this->assertTrue($tampered->close());

            try {
                (new \ReflectionMethod(SystemUpdateService::class, 'unpackUploadedBundle'))
                    ->invoke(
                        app(SystemUpdateService::class),
                        new UploadedFile($bundlePath, 'tampered.zip', 'application/zip', null, true),
                        $staged,
                    );
                $this->fail('Forged ZIP signature was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('signature could not be verified', $e->getMessage());
            }
        } finally {
            @unlink($payload);
            @unlink($bundlePath);
            @unlink($staged);
        }
    }

    public function test_upload_endpoint_rejects_missing_confirmation_or_non_zip_file(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);

        $this->actingAs($admin)->post('/admin/system-updates/upload', [
            'update_bundle' => UploadedFile::fake()->createWithContent('unsafe.txt', 'not signed'),
        ])->assertSessionHasErrors('confirmation');

        $this->actingAs($admin)->post('/admin/system-updates/verify', [
            'update_bundle' => UploadedFile::fake()->createWithContent('unsafe.txt', 'not signed'),
        ])->assertSessionHasErrors('update_bundle');

        $this->actingAs($admin)->post('/admin/system-updates/upload', [
            'confirmation' => 'INSTALL',
            'update_bundle' => UploadedFile::fake()->createWithContent('unsafe.txt', 'not signed'),
        ])->assertSessionHasErrors('update_bundle');
    }

    public function test_file_backup_is_hashed_and_tampered_restore_fails_closed(): void
    {
        $filename = 'app/AIWMBackupIntegrityProbe-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $backup = storage_path('framework/testing/aiwm-backup-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);
        $backupMethod = new \ReflectionMethod($service, 'backupFiles');
        $restoreMethod = new \ReflectionMethod($service, 'restoreBackup');

        try {
            file_put_contents($target, 'safe-original');
            $backupMethod->invoke($service, [$filename => hash('sha256', 'safe-next')], $backup);
            $this->assertFileExists($backup.'/hashes.json');

            file_put_contents($target, 'changed-by-update');
            $restoreMethod->invoke($service, $backup);
            $this->assertSame('safe-original', file_get_contents($target));

            file_put_contents($backup.'/original/'.$filename, 'tampered-backup');
            file_put_contents($target, 'do-not-overwrite-on-corruption');
            try {
                $restoreMethod->invoke($service, $backup);
                $this->fail('Corrupted backup must be rejected before file restore.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Backup integrity verification failed', $e->getMessage());
            }
            $this->assertSame('do-not-overwrite-on-corruption', file_get_contents($target));
        } finally {
            @unlink($target);
            File::deleteDirectory($backup);
        }
    }

    private function fakeRelease(string $manifest, string $signature): void
    {
        $prefix = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/';
        $assets = [];
        foreach ([
            'AIWMWeb-Laravel-release.json' => $manifest,
            'AIWMWeb-Laravel-release.sig' => $signature,
            'AIWMWeb-Laravel-update.zip' => 'mock-package-not-downloaded',
        ] as $name => $contents) {
            $assets[] = ['name' => $name, 'size' => strlen($contents), 'browser_download_url' => $prefix.$name];
        }
        Http::fake([
            'https://api.github.com/repos/walidatiyaai2025-gif/AIMWWeb/releases/latest' => Http::response([
                'draft' => false, 'prerelease' => false, 'assets' => $assets,
            ]),
            $prefix.'AIWMWeb-Laravel-release.json' => Http::response($manifest),
            $prefix.'AIWMWeb-Laravel-release.sig' => Http::response($signature),
        ]);
    }
}
