<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CanonicalPlatformRuntimeHardReloadTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-4BAE8344AF';

    public function test_generated_reconciliation_closes_the_canonical_hard_reload_identity(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($row);
        $this->assertSame('platform', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Routes.razor', $row['current_source']);
        $this->assertSame('@Navigation.Uri -> @Navigation.Uri', $row['visible_control']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertFalse((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
    }

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

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {

        return json_decode((string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
