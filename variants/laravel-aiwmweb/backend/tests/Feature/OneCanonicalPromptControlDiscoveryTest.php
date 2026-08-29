<?php

namespace Tests\Feature;

use Tests\TestCase;

class OneCanonicalPromptControlDiscoveryTest extends TestCase
{
    public function test_emit_pending_prompt_template_controls_for_single_operation_selection(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $rows = collect($payload['operations'])
            ->filter(fn (array $row): bool =>
                ($row['current_source'] ?? null) === 'src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor'
                && ($row['migration_state'] ?? null) === 'PENDING'
            )
            ->map(fn (array $row): array => [
                'operation_id' => $row['operation_id'] ?? null,
                'domain' => $row['domain'] ?? null,
                'kind' => $row['kind'] ?? null,
                'route_screen' => $row['route_screen'] ?? null,
                'visible_control' => $row['visible_control'] ?? null,
                'handler_method' => $row['handler_method'] ?? null,
                'mutation' => $row['mutation'] ?? null,
                'tenant_owned' => $row['tenant_owned'] ?? null,
                'risk' => $row['risk'] ?? null,
                'verification' => $row['verification'] ?? null,
                'migration_state' => $row['migration_state'] ?? null,
            ])
            ->values()
            ->all();

        self::fail('PROMPT_CONTROL_DISCOVERY='.json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
