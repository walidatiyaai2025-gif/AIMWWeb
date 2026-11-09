<?php

namespace Tests\Feature;

use App\Platform\SystemUpdateService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class SystemUpdatePackageTransportTest extends TestCase
{
    private const PACKAGE_URL = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-update.zip';

    public function test_signed_zip_download_preserves_github_redirect_allowlist_and_bounded_streaming(): void
    {
        Http::fake([self::PACKAGE_URL => Http::response('placeholder-download')]);
        $path = tempnam(sys_get_temp_dir(), 'aiwm-release-stream-');
        $this->assertNotFalse($path);

        try {
            // A fake HTTP response does not stream into Guzzle's sink. It must
            // fail closed before the installer can enter maintenance mode.
            try {
                (new ReflectionMethod(SystemUpdateService::class, 'downloadVerifiedPackage'))
                    ->invoke(app(SystemUpdateService::class), $this->asset(), $path, str_repeat('a', 64));
                $this->fail('A response without a matching signed ZIP was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('SHA-256 verification failed', $exception->getMessage());
            }

            Http::assertSentCount(1);
            $request = Http::recorded()[0][0];
            $options = $request->getOptions();

            $this->assertSame(180, $options['timeout']);
            $this->assertGreaterThanOrEqual(3, $options['connect_timeout']);
            $this->assertLessThanOrEqual(15, $options['connect_timeout']);
            $this->assertSame(['https'], $options['allow_redirects']['protocols']);
            $this->assertSame(3, $options['allow_redirects']['max']);
            $this->assertSame($path, $options['sink']);

            $redirect = $options['allow_redirects']['on_redirect'];
            $redirect(null, null, new Uri('https://release-assets.githubusercontent.com/signed-package'));
            $redirect(null, null, new Uri('https://objects.githubusercontent.com/signed-package'));

            foreach ([
                'http://github.com/unsafe',
                'https://127.0.0.1/internal',
                'https://example.com/unsafe',
                'https://github.com:8443/nonstandard-port',
                'https://user:pass@github.com/credentials',
            ] as $destination) {
                try {
                    $redirect(null, null, new Uri($destination));
                    $this->fail('Untrusted download redirect was allowed: '.$destination);
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('Unapproved release metadata redirect', $exception->getMessage());
                }
            }

            $this->assertIsCallable($options['progress']);
            $options['progress'](0, 157286400);

            try {
                $options['progress'](0, 157286401);
                $this->fail('A streamed ZIP over 150 MiB was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('streaming size limit', $exception->getMessage());
            }

            $this->assertIsCallable($options['on_headers']);
            $options['on_headers'](new PsrResponse(200, ['Content-Length' => '157286400']));
            try {
                $options['on_headers'](new PsrResponse(200, ['Content-Length' => '157286401']));
                $this->fail('An oversized Content-Length was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('streaming size limit', $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_signed_zip_download_rejects_unapproved_source_before_network_request(): void
    {
        Http::fake();

        foreach ([
            'http://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-update.zip',
            'https://evil.example/update.zip',
            'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-release.json',
            'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/v9.9.9/AIWMWeb-Laravel-update.zip/../../unexpected',
        ] as $url) {
            try {
                (new ReflectionMethod(SystemUpdateService::class, 'downloadVerifiedPackage'))
                    ->invoke(app(SystemUpdateService::class), ['url' => $url, 'size' => 100, 'id' => 2], '/tmp/unused-aiwm-download', str_repeat('a', 64));
                $this->fail('Unapproved source was accepted: '.$url);
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('URL is not approved', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_signed_zip_download_rejects_wrong_sha_without_modifying_installed_receipt(): void
    {
        Http::fake([self::PACKAGE_URL => Http::response('placeholder-download')]);
        $path = tempnam(sys_get_temp_dir(), 'aiwm-release-hash-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'bad-package-bytes');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Package download or SHA-256 verification failed.');
            (new ReflectionMethod(SystemUpdateService::class, 'downloadVerifiedPackage'))
                ->invoke(app(SystemUpdateService::class), $this->asset(), $path, hash('sha256', 'different-signed-bytes'));
        } finally {
            @unlink($path);
        }
    }

    /** @return array{url:string,size:int,id:?int} */
    private function asset(): array
    {
        return ['url' => self::PACKAGE_URL, 'size' => 19, 'id' => 12345];
    }
}
