<?php

namespace Tests\Feature;

use App\Logging\RedactSecretsProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class ProductionRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_is_internal_only_and_emits_correlation_headers(): void
    {
        $response = $this->withHeaders([
            'X-Request-ID' => 'request-12345678',
            'X-Correlation-ID' => 'correlation-12345678',
        ])->getJson('/health/live');

        $response
            ->assertOk()
            ->assertJson(['status' => 'live', 'service' => 'laravel-aiwmweb'])
            ->assertHeader('X-Request-ID', 'request-12345678')
            ->assertHeader('X-Correlation-ID', 'correlation-12345678');
    }

    public function test_readiness_reports_internal_dependencies_without_requiring_wordpress(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $checks = $response->json('checks');

        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('redis', $checks);
        $this->assertArrayHasKey('storage', $checks);
        $this->assertArrayHasKey('queue', $checks);
        $this->assertArrayHasKey('scheduler', $checks);
        $this->assertArrayNotHasKey('wordpress', $checks);
    }

    public function test_structured_log_processor_redacts_secret_fields_recursively(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'redaction test',
            context: [
                'request_id' => 'safe-request-id',
                'password' => 'do-not-log',
                'nested' => ['api_key' => 'do-not-log-either', 'safe' => 'visible'],
            ],
        );

        $processed = (new RedactSecretsProcessor)($record);

        $this->assertSame('safe-request-id', $processed->context['request_id']);
        $this->assertSame('[REDACTED]', $processed->context['password']);
        $this->assertSame('[REDACTED]', $processed->context['nested']['api_key']);
        $this->assertSame('visible', $processed->context['nested']['safe']);
    }
}
