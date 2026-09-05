<?php

namespace Tests\Feature;

use App\Http\Controllers\ErrorReadController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErrorCopyDetailsTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-SYNC-89777052CB';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_the_pending_error_copy_control(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/Error', $row['route_screen']);
        $this->assertStringContainsString('CopyAsync', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Error.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
    }

    public function test_real_error_page_renders_the_copy_control_from_only_safe_tracking_values(): void
    {
        Carbon::setTestNow('2026-08-30 20:45:00');

        $response = $this->withHeaders([
            'X-Request-ID' => 'error-copy-request-0001',
            'X-Correlation-ID' => 'error-copy-correlation-0001',
        ])->get('/Error?exception=database-password-secret&tenant=foreign-secret');

        $response
            ->assertOk()
            ->assertSee('Copy error details')
            ->assertSee('data-copy-error-details', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('data-copy-error-success', false)
            ->assertSee('data-copy-error-error', false)
            ->assertSee('data-copy-error-retry', false)
            ->assertSee('Retry copy')
            ->assertSee('error-details-payload', false)
            ->assertDontSee('database-password-secret')
            ->assertDontSee('foreign-secret')
            ->assertDontSee('Stack trace')
            ->assertDontSee('Exception message');

        $payload = $this->extractPayload($response->getContent());
        $this->assertSame([
            'errorId' => 'error-copy-request-0001',
            'correlationId' => 'error-copy-correlation-0001',
        ], $payload);
        $this->assertCount(2, $payload);
    }

    public function test_copy_control_preserves_the_existing_anonymous_error_route_and_does_not_claim_sibling_operations(): void
    {
        $route = Route::getRoutes()->getByName('canonical.error');

        $this->assertNotNull($route);
        $this->assertSame(ErrorReadController::class, $route->getActionName());
        $this->assertSame('Error', $route->uri());
        $this->assertSame([], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());

        $html = $this->get('/Error')->assertOk()->getContent();
        $this->assertStringContainsString('data-canonical-operation="'.self::OPERATION_ID.'"', $html);
        $this->assertStringContainsString('data-canonical-operation="AIMW-CONT-85394A0E55"', $html);
        $this->assertStringNotContainsString('data-canonical-operation="AIMW-CONT-8B3518EF80"', $html);
        $this->assertStringNotContainsString('/tenants/', $html);
    }

    public function test_invalid_tracking_headers_are_not_exposed_to_the_clipboard_payload(): void
    {
        $response = $this->withHeaders([
            'X-Request-ID' => '<script>alert(1)</script>',
            'X-Correlation-ID' => 'password=must-not-copy',
        ])->get('/Error');

        $response->assertOk();
        $payload = $this->extractPayload($response->getContent());

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['errorId']);
        $this->assertSame($payload['errorId'], $payload['correlationId']);
        $this->assertStringNotContainsString('<script>', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('must-not-copy', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function extractPayload(string $html): array
    {
        $matched = preg_match(
            '/<script id="error-details-payload" type="application\/json">(.*?)<\/script>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched, 'The safe error-details payload must be rendered into the real page.');

        return json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
