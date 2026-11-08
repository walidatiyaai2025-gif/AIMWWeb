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

    public function test_release_metadata_retries_transient_503_but_not_404(): void
    {
        $url = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-release.json';
        Http::fake([
            $url => Http::sequence()
                ->push('temporarily unavailable', 503)
                ->push('signed-manifest-fixture', 200),
        ]);

        $download = new \ReflectionMethod(SystemUpdateService::class, 'downloadSmall');
        $this->assertSame('signed-manifest-fixture', $download->invoke(app(SystemUpdateService::class), $url, 4096));
        Http::assertSentCount(2);
    }

    public function test_release_metadata_404_fails_closed_without_retry(): void
    {
        $url = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-release.json';
        Http::fake([$url => Http::response('not found', 404)]);
        $download = new \ReflectionMethod(SystemUpdateService::class, 'downloadSmall');

        try {
            $download->invoke(app(SystemUpdateService::class), $url, 4096);
            $this->fail('A missing signed release asset must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('metadata download failed', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_release_metadata_request_bounds_administrator_timeout_configuration(): void
    {
        config()->set('system_updates.metadata_connect_timeout', 999);
        config()->set('system_updates.metadata_timeout', 999);
        $request = (new \ReflectionMethod(SystemUpdateService::class, 'releaseMetadataRequest'))
            ->invoke(app(SystemUpdateService::class));

        $this->assertSame(15, $request->getOptions()['connect_timeout']);
        $this->assertSame(90, $request->getOptions()['timeout']);
        $redirectOptions = $request->getOptions()['allow_redirects'];
        $this->assertSame(3, $redirectOptions['max']);
        $this->assertSame(['https'], $redirectOptions['protocols']);
        $this->assertIsCallable($redirectOptions['on_redirect']);
    }

    public function test_release_metadata_refuses_unapproved_cdn_redirects(): void
    {
        $request = (new \ReflectionMethod(SystemUpdateService::class, 'releaseMetadataRequest'))
            ->invoke(app(SystemUpdateService::class));
        $callback = $request->getOptions()['allow_redirects']['on_redirect'];

        // Official, authenticated GitHub asset-CDN destinations remain allowed.
        $callback(null, null, new \GuzzleHttp\Psr7\Uri('https://release-assets.githubusercontent.com/allowed'));

        foreach ([
            'https://example.com/metadata',
            'https://127.0.0.1/internal',
            'https://github.com:8443/asset',
            'http://github.com/asset',
        ] as $url) {
            try {
                $callback(null, null, new \GuzzleHttp\Psr7\Uri($url));
                $this->fail('Unsafe signed metadata redirect was allowed: '.$url);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Unapproved release metadata redirect', $e->getMessage());
            }
        }
    }

    public function test_release_metadata_rejects_oversized_signed_asset_before_verification(): void
    {
        $url = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-release.json';
        Http::fake([$url => Http::response(str_repeat('X', 4097))]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Release metadata download failed');
        (new \ReflectionMethod(SystemUpdateService::class, 'downloadSmall'))
            ->invoke(app(SystemUpdateService::class), $url, 4096);
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
        foreach (['../.env', 'storage/logs/app.log', 'app/../../.env', 'public/index.php', 'public/.htaccess', 'public/downloads/other.zip', 'public/downloads/aimw-connector.zip/evil.php', 'app/.env', 'app\\x.php', 'database/migrations/new.php'] as $path) {
            try {
                $method->invoke($service, $path);
                $this->fail('Unsafe update path accepted: '.$path);
            } catch (RuntimeException) {
                // Expected rejection.
            }
        }
        foreach (['app/Http/Controllers/Safe.php', 'public/build/assets/app.js', 'public/downloads/aimw-connector.zip', 'aiwm-cpanel-https-pull.php', 'vendor/composer/installed.json', 'vendor/symfony/http-foundation/Session/Storage/Handler/AbstractSessionHandler.php'] as $path) {
            $method->invoke($service, $path);
            $this->assertTrue(true);
        }
    }

    public function test_atomic_signed_update_keeps_public_assets_web_readable_under_restrictive_umask(): void
    {
        $service = new SystemUpdateService;
        $method = new \ReflectionMethod($service, 'applyStagedFile');
        $name = 'public/build/ci-ssh-permission-'.bin2hex(random_bytes(5)).'.js';
        $path = public_path(substr($name, strlen('public/')));
        $directory = dirname($path);
        $createdDirectory = ! is_dir($directory);
        if ($createdDirectory) {
            $this->assertTrue(mkdir($directory, 0755, true));
        }
        $source = tempnam(sys_get_temp_dir(), 'aiwm-signed-');
        $this->assertNotFalse($source);
        $contents = 'console.info("Signed cPanel asset permission acceptance");';
        file_put_contents($source, $contents);
        $previous = umask(0077);
        try {
            $method->invoke($service, $name, $source, hash('sha256', $contents), bin2hex(random_bytes(8)));
            clearstatcache(true, $path);
            $this->assertFileExists($path);
            $this->assertSame(0644, fileperms($path) & 0777);
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            umask($previous);
            @unlink($source);
            @unlink($path);
            if ($createdDirectory) {
                @rmdir($directory);
            }
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

    public function test_exclusive_apply_succeeds_and_removes_temporary_file(): void
    {
        $filename = 'app/AIWMExclusiveApplyProbe-'.bin2hex(random_bytes(6)).'.php';
        $target = base_path($filename);
        $source = tempnam(sys_get_temp_dir(), 'aiwm-apply-normal-');
        $id = 'exclusive-'.bin2hex(random_bytes(5));
        $temp = dirname($target).'/.aiwm-update-'.$id;

        try {
            $this->assertNotFalse($source);
            file_put_contents($source, 'verified-new-payload');
            file_put_contents($target, 'previous-payload');

            (new \ReflectionMethod(SystemUpdateService::class, 'applyStagedFile'))
                ->invoke(app(SystemUpdateService::class), $filename, $source, hash('sha256', 'verified-new-payload'), $id);

            $this->assertSame('verified-new-payload', file_get_contents($target));
            $this->assertFileDoesNotExist($temp);
        } finally {
            @unlink($target);
            @unlink($temp);
            if (is_string($source)) {
                @unlink($source);
            }
        }
    }

    public function test_exclusive_apply_rejects_existing_regular_temporary_file(): void
    {
        $filename = 'app/AIWMExclusiveOccupiedProbe-'.bin2hex(random_bytes(6)).'.php';
        $target = base_path($filename);
        $source = tempnam(sys_get_temp_dir(), 'aiwm-apply-occupied-');
        $id = 'occupied-'.bin2hex(random_bytes(5));
        $temp = dirname($target).'/.aiwm-update-'.$id;

        try {
            $this->assertNotFalse($source);
            file_put_contents($source, 'verified-new-payload');
            file_put_contents($temp, 'existing-protected-payload');

            try {
                (new \ReflectionMethod(SystemUpdateService::class, 'applyStagedFile'))
                    ->invoke(app(SystemUpdateService::class), $filename, $source, hash('sha256', 'verified-new-payload'), $id);
                $this->fail('A preexisting update temporary file was overwritten.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('temporary path', $e->getMessage());
            }

            $this->assertSame('existing-protected-payload', file_get_contents($temp));
            $this->assertFileDoesNotExist($target);
        } finally {
            @unlink($target);
            @unlink($temp);
            if (is_string($source)) {
                @unlink($source);
            }
        }
    }

    public function test_apply_rechecks_swapped_parent_and_never_writes_outside_app(): void
    {
        $directory = 'AIWMApplySymlinkProbe-'.bin2hex(random_bytes(5));
        $parent = base_path('app/'.$directory);
        $outside = sys_get_temp_dir().'/aiwm-apply-outside-'.bin2hex(random_bytes(6));
        $source = tempnam(sys_get_temp_dir(), 'aiwm-apply-source-');
        $filename = 'app/'.$directory.'/Updated.php';

        try {
            $this->assertTrue(mkdir($outside, 0700));
            file_put_contents($outside.'/Updated.php', 'external-unchanged');
            file_put_contents($source, 'signed-new-file');
            // Simulate a parent-directory swap *after* staging/backup and
            // immediately before the updater applies its signed file.
            $this->assertTrue(symlink($outside, $parent));

            try {
                (new \ReflectionMethod(SystemUpdateService::class, 'applyStagedFile'))
                    ->invoke(app(SystemUpdateService::class), $filename, $source, hash('sha256', 'signed-new-file'), 'probe');
                $this->fail('Applying a staged update followed a swapped parent symlink.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symbolic link', $e->getMessage());
            }

            $this->assertSame('external-unchanged', file_get_contents($outside.'/Updated.php'));
            $this->assertFileDoesNotExist($outside.'/.aiwm-update-probe');
        } finally {
            @unlink($parent);
            @unlink($outside.'/Updated.php');
            @rmdir($outside);
            if (is_string($source)) {
                @unlink($source);
            }
        }
    }

    public function test_apply_rejects_planted_temporary_symlink_before_copy(): void
    {
        $filename = 'app/AIWMApplyTempProbe-'.bin2hex(random_bytes(6)).'.php';
        $target = base_path($filename);
        $outside = tempnam(sys_get_temp_dir(), 'aiwm-apply-temp-outside-');
        $source = tempnam(sys_get_temp_dir(), 'aiwm-apply-temp-source-');
        $id = 'planted-'.bin2hex(random_bytes(4));
        $temp = dirname($target).'/.aiwm-update-'.$id;

        try {
            $this->assertNotFalse($outside);
            $this->assertNotFalse($source);
            file_put_contents($outside, 'private-unchanged');
            file_put_contents($source, 'signed-update');
            $this->assertTrue(symlink($outside, $temp));

            try {
                (new \ReflectionMethod(SystemUpdateService::class, 'applyStagedFile'))
                    ->invoke(app(SystemUpdateService::class), $filename, $source, hash('sha256', 'signed-update'), $id);
                $this->fail('A planted update temp symlink was followed.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('temporary path', $e->getMessage());
            }

            $this->assertSame('private-unchanged', file_get_contents($outside));
            $this->assertFileDoesNotExist($target);
            $this->assertTrue(is_link($temp));
        } finally {
            @unlink($temp);
            @unlink($target);
            if (is_string($outside)) {
                @unlink($outside);
            }
            if (is_string($source)) {
                @unlink($source);
            }
        }
    }

    public function test_rollback_removes_file_created_only_during_failed_update(): void
    {
        $filename = 'app/AIWMRollbackCreated-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $backup = storage_path('framework/testing/aiwm-rollback-created-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);

        try {
            $this->assertFileDoesNotExist($target);
            (new \ReflectionMethod($service, 'backupFiles'))
                ->invoke($service, [$filename => hash('sha256', 'new-update-file')], $backup);
            file_put_contents($target, 'new-update-file');

            (new \ReflectionMethod($service, 'restoreBackup'))->invoke($service, $backup);

            $this->assertFileDoesNotExist($target);
        } finally {
            @unlink($target);
            File::deleteDirectory($backup);
        }
    }

    public function test_rollback_rejects_undeletable_new_path_instead_of_reporting_success(): void
    {
        $filename = 'app/AIWMRollbackBlocked-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $backup = storage_path('framework/testing/aiwm-rollback-blocked-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);

        try {
            $this->assertFileDoesNotExist($target);
            (new \ReflectionMethod($service, 'backupFiles'))
                ->invoke($service, [$filename => hash('sha256', 'new-update-file')], $backup);
            $this->assertTrue(mkdir($target));

            try {
                (new \ReflectionMethod($service, 'restoreBackup'))->invoke($service, $backup);
                $this->fail('Rollback silently accepted a path it could not remove.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('could not remove a new file', $e->getMessage());
            }

            $this->assertDirectoryExists($target);
        } finally {
            @rmdir($target);
            File::deleteDirectory($backup);
        }
    }

    public function test_rollback_restores_original_from_verified_staged_file(): void
    {
        $filename = 'app/AIWMRollbackAtomic-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $backup = storage_path('framework/testing/aiwm-rollback-atomic-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);

        try {
            file_put_contents($target, 'original-verified-content');
            (new \ReflectionMethod($service, 'backupFiles'))
                ->invoke($service, [$filename => hash('sha256', 'updated-content')], $backup);
            file_put_contents($target, 'updated-content');

            (new \ReflectionMethod($service, 'restoreBackup'))->invoke($service, $backup);

            $this->assertSame('original-verified-content', file_get_contents($target));
            $this->assertSame([], glob(dirname($target).'/.aiwm-rollback-*') ?: []);
        } finally {
            @unlink($target);
            File::deleteDirectory($backup);
        }
    }

    public function test_rollback_rejects_tampered_backup_and_keeps_live_file_intact(): void
    {
        $filename = 'app/AIWMRollbackTampered-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $backup = storage_path('framework/testing/aiwm-rollback-tamper-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);

        try {
            file_put_contents($target, 'original');
            (new \ReflectionMethod($service, 'backupFiles'))
                ->invoke($service, [$filename => hash('sha256', 'updated')], $backup);
            file_put_contents($target, 'updated-must-remain');
            file_put_contents($backup.'/original/'.$filename, 'tampered');

            try {
                (new \ReflectionMethod($service, 'restoreBackup'))->invoke($service, $backup);
                $this->fail('A tampered rollback backup was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Backup integrity verification failed', $e->getMessage());
            }

            $this->assertSame('updated-must-remain', file_get_contents($target));
            $this->assertSame([], glob(dirname($target).'/.aiwm-rollback-*') ?: []);
        } finally {
            @unlink($target);
            File::deleteDirectory($backup);
        }
    }

    public function test_rollback_refuses_symlink_swapped_target_and_preserves_external_file(): void
    {
        $filename = 'app/AIWMRollbackSymlinkProbe-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $outside = tempnam(sys_get_temp_dir(), 'aiwm-rollback-outside-');
        $backup = storage_path('framework/testing/aiwm-rollback-link-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);
        $backupMethod = new \ReflectionMethod($service, 'backupFiles');
        $restoreMethod = new \ReflectionMethod($service, 'restoreBackup');

        try {
            $this->assertNotFalse($outside);
            file_put_contents($outside, 'private-file-must-not-change');
            file_put_contents($target, 'original');
            $backupMethod->invoke($service, [$filename => hash('sha256', 'updated')], $backup);

            // An attacker swaps the app file for a symlink between the verified
            // backup and automatic rollback of an interrupted update.
            unlink($target);
            $this->assertTrue(symlink($outside, $target));

            try {
                $restoreMethod->invoke($service, $backup);
                $this->fail('Rollback followed a swapped symbolic link.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('symbolic link', $e->getMessage());
            }

            $this->assertSame('private-file-must-not-change', file_get_contents($outside));
            $this->assertTrue(is_link($target));
        } finally {
            @unlink($target);
            if (is_string($outside)) {
                @unlink($outside);
            }
            File::deleteDirectory($backup);
        }
    }

    public function test_rollback_refuses_tampered_inventory_path_before_touching_files(): void
    {
        $filename = 'app/AIWMRollbackInventoryProbe-'.bin2hex(random_bytes(5)).'.php';
        $target = base_path($filename);
        $outside = tempnam(sys_get_temp_dir(), 'aiwm-rollback-inventory-');
        $backup = storage_path('framework/testing/aiwm-rollback-path-'.bin2hex(random_bytes(5)));
        $service = app(SystemUpdateService::class);
        $backupMethod = new \ReflectionMethod($service, 'backupFiles');
        $restoreMethod = new \ReflectionMethod($service, 'restoreBackup');

        try {
            $this->assertNotFalse($outside);
            file_put_contents($outside, 'private-data');
            file_put_contents($target, 'original');
            $backupMethod->invoke($service, [$filename => hash('sha256', 'updated')], $backup);

            // A backup inventory is private state, but rollback must validate
            // it again rather than trusting altered JSON path keys.
            file_put_contents($backup.'/files.json', json_encode([
                '../storage/app/foreign-data' => false,
            ], JSON_THROW_ON_ERROR));

            try {
                $restoreMethod->invoke($service, $backup);
                $this->fail('Rollback accepted a traversal path from inventory.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Unsafe file path', $e->getMessage());
            }

            $this->assertSame('original', file_get_contents($target));
            $this->assertSame('private-data', file_get_contents($outside));
        } finally {
            @unlink($target);
            if (is_string($outside)) {
                @unlink($outside);
            }
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
