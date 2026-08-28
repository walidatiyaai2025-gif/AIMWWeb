<?php

namespace Tests\Feature;

use Tests\TestCase;

class OperationsApiCanonicalDiscoveryTest extends TestCase
{
    public function test_emit_canonical_operations_api_row_for_recovery(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $row = collect($payload['operations'])->firstWhere('operation_id', 'AIMW-OPER-ABB41FC891');

        $this->assertNotNull($row);
        $this->fail('CANONICAL_OPERATION_ROW='.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
