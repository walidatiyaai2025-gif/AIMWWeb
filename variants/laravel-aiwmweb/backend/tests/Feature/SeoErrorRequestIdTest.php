<?php

namespace Tests\Feature;

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
}
