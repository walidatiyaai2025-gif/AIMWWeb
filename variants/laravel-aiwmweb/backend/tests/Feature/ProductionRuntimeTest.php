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

    public function test_monolog_message_redaction_masks_credentials_before_output(): void
    {
        $message = 'Sync failed Authorization: Bearer BEARER_MESSAGE_CANARY '
            .'Authorization: Basic BASIC_MESSAGE_CANARY '
            .'X-API-Key: HEADER_MESSAGE_CANARY '
            .'api_key=API_MESSAGE_CANARY '
            .'provider sk-THIS_IS_A_FAKE_KEY_1234567890 next retry scheduled';
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'test',
            level: Level::Warning,
            message: $message,
            context: ['api_key' => 'STRUCTURED_CONTEXT_CANARY', 'safe' => 'visible'],
            extra: ['access_token' => 'STRUCTURED_EXTRA_CANARY', 'request_id' => 'safe-request'],
        );

        $processed = (new RedactSecretsProcessor)($record);

        $this->assertSame(
            'Sync failed Authorization: Bearer [REDACTED] '
                .'Authorization: Basic [REDACTED] '
                .'X-API-Key: [REDACTED] '
                .'api_key=[REDACTED] '
                .'provider [REDACTED] next retry scheduled',
            $processed->message,
        );
        $this->assertSame('[REDACTED]', $processed->context['api_key']);
        $this->assertSame('[REDACTED]', $processed->extra['access_token']);
        $this->assertSame('visible', $processed->context['safe']);
        $this->assertSame('safe-request', $processed->extra['request_id']);
        foreach ([
            'BEARER_MESSAGE_CANARY', 'BASIC_MESSAGE_CANARY',
            'HEADER_MESSAGE_CANARY', 'API_MESSAGE_CANARY',
            'sk-THIS_IS_A_FAKE_KEY_1234567890',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $processed->message);
        }
        $this->assertSame($message, $record->message);
        $this->assertSame('STRUCTURED_CONTEXT_CANARY', $record->context['api_key']);
        $this->assertSame('STRUCTURED_EXTRA_CANARY', $record->extra['access_token']);
    }

    public function test_monolog_message_redaction_preserves_nonsecret_messages(): void
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Sync completed: 17 records queued without errors',
            context: ['status' => 'ok'],
        );
        $processed = (new RedactSecretsProcessor)($record);

        $this->assertSame($record->message, $processed->message);
        $this->assertSame('ok', $processed->context['status']);
    }

    public function test_structured_log_processor_fails_closed_at_depth_limit_in_context_and_extra(): void
    {
        $deep = ['password' => 'DEEP_PASSWORD_SECRET', 'safe' => 'DEEP_CANARY', 7 => ['token' => 'DEEP_TOKEN_SECRET']];
        for ($level = 0; $level < 5; $level++) {
            $deep = ['layer'.$level => $deep];
        }

        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'safe log message',
            context: ['safe' => 'visible', 'nested' => $deep],
            extra: ['request_id' => 'public-request', 'nested' => $deep],
        );
        $processed = (new RedactSecretsProcessor)($record);

        $this->assertSame('visible', $processed->context['safe']);
        $this->assertSame('public-request', $processed->extra['request_id']);
        $this->assertStringNotContainsString('DEEP_PASSWORD_SECRET', json_encode($processed->context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('DEEP_TOKEN_SECRET', json_encode($processed->extra, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('DEEP_CANARY', json_encode($processed->context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('DEEP_CANARY', json_encode($processed->extra, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('[REDACTED]', json_encode($processed->context, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('[REDACTED]', json_encode($processed->extra, JSON_THROW_ON_ERROR));

        // The immutable Monolog record supplied to the processor keeps its
        // original values; only the processed copy is safe to emit.
        $this->assertStringContainsString('DEEP_PASSWORD_SECRET', json_encode($record->context, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('DEEP_TOKEN_SECRET', json_encode($record->extra, JSON_THROW_ON_ERROR));
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
