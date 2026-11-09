<?php

namespace Tests\Feature;

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SeoErrorRequestIdTest extends TestCase
{
    public function test_uncaught_api_failure_keeps_a_safe_request_reference_without_revealing_internal_errors(): void
    {
        config()->set('app.debug', false);

        Route::get('/api/seo-reference-regression', static function (): never {
            throw new \RuntimeException('Bearer PRIVATE_AI_TOKEN SQLSTATE /home/private/.env');
        });

        $response = $this->withHeader('X-Request-ID', 'seo-request-500-0001')
            ->getJson('/api/seo-reference-regression');

        $response->assertStatus(500)
            ->assertHeader('X-Request-ID', 'seo-request-500-0001');

        $this->assertStringNotContainsString('PRIVATE_AI_TOKEN', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('/home/private/.env', $response->getContent());
    }

    public function test_ai_platform_failures_are_classified_without_leaking_provider_errors(): void
    {
        config()->set('app.debug', false);

        $cases = [
            AiFailureKind::Timeout->value => [AiFailureKind::Timeout, 504],
            AiFailureKind::InvalidCredentials->value => [AiFailureKind::InvalidCredentials, 503],
            AiFailureKind::ProviderUnavailable->value => [AiFailureKind::ProviderUnavailable, 503],
            AiFailureKind::InvalidOutput->value => [AiFailureKind::InvalidOutput, 422],
            AiFailureKind::RateLimit->value => [AiFailureKind::RateLimit, 429],
            AiFailureKind::Unknown->value => [AiFailureKind::Unknown, 502],
        ];

        foreach ($cases as $name => [$kind, $expectedStatus]) {
            $path = '/api/seo-ai-failure-'.str_replace('_', '-', $name);
            Route::get($path, static function () use ($kind): never {
                // Even if an upstream provider emitted a 500 with a sensitive
                // message, public API status/code must derive from the safe enum.
                throw new AiPlatformException($kind, 'Bearer SECRET_AI_PROVIDER_KEY /home/private/.env', false, 500);
            });

            $response = $this->withHeader('X-Request-ID', 'seo-ai-classification-0001')
                ->getJson($path);

            $response->assertStatus($expectedStatus)
                ->assertHeader('X-Request-ID', 'seo-ai-classification-0001')
                ->assertJsonPath('code', $kind->value);

            $this->assertStringNotContainsString('SECRET_AI_PROVIDER_KEY', $response->getContent());
            $this->assertStringNotContainsString('/home/private/.env', $response->getContent());
        }
    }

}
