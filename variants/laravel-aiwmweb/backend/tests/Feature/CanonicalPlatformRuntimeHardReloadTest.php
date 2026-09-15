<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CanonicalPlatformRuntimeHardReloadTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-4BAE8344AF';

    public function test_runtime_control_uses_exact_current_url_with_no_callback_or_api_mutation(): void
    {
        $source = (string) file_get_contents(resource_path('js/runtime-error-boundary.tsx'));
        $this->assertStringContainsString("RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID = '".self::OPERATION_ID."'", $source);
        $this->assertStringContainsString('href={window.location.href}', $source);
        $this->assertStringContainsString('data-canonical-operation={RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID}', $source);
        $this->assertStringNotContainsString('onClick={() => window.location.reload()}', $source);
        $this->assertStringNotContainsString('/api/', $this->hardReloadControlSource($source));
    }

    private function hardReloadControlSource(string $source): string
    {
        $start = strpos($source, 'export function RuntimeErrorHardReloadControl');
        $end = strpos($source, 'export class RuntimeErrorBoundary');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($source, (int) $start, (int) $end - (int) $start);
    }
}
