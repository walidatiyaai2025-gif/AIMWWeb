<?php

namespace Tests\Feature;

use Tests\TestCase;

class RuntimeErrorRecoverTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-C6260410D1';

    public function test_exact_canonical_operation_is_the_pending_routes_recover_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:Routes', $operation['route_screen']);
        $this->assertSame('Recover [Recover]', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Routes.razor',
            $operation['current_source'],
        );
    }

    public function test_runtime_wires_exact_recover_contract_without_route_tenant_or_server_mutation(): void
    {
        $boundary = (string) file_get_contents(resource_path('js/runtime-error-boundary.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(
            "export const RUNTIME_ERROR_RECOVER_OPERATION_ID = '".self::OPERATION_ID."';",
            $boundary,
        );
        $this->assertStringContainsString(
            'data-canonical-operation={RUNTIME_ERROR_RECOVER_OPERATION_ID}',
            $boundary,
        );
        $this->assertStringContainsString('>Try to recover</button>', $boundary);
        $this->assertStringContainsString('this.setState({ error: null })', $boundary);
        $this->assertStringNotContainsString('apiRequest(', $boundary);
        $this->assertStringNotContainsString('fetch(', $boundary);
        $this->assertStringNotContainsString('tenantSlug', $boundary);
        $this->assertStringNotContainsString('window.location.href', $boundary);
        $this->assertSame(1, substr_count($boundary, self::OPERATION_ID));

        $this->assertStringContainsString("import { RuntimeErrorBoundary } from './runtime-error-boundary';", $app);
        $this->assertStringContainsString('<RuntimeErrorBoundary>', $app);
        $this->assertStringContainsString('</RuntimeErrorBoundary>', $app);
    }
}
