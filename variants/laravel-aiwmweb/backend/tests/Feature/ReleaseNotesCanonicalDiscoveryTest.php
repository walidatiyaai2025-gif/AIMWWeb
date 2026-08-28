<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReleaseNotesCanonicalDiscoveryTest extends TestCase
{
    public function test_emit_pending_release_notes_canonical_rows(): void
    {
        $reconciliation = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $rows = collect($reconciliation['operations'])
            ->filter(static function (array $row): bool {
                if (($row['migration_state'] ?? null) !== 'PENDING') {
                    return false;
                }

                $haystack = strtolower(implode(' ', [
                    (string) ($row['route_screen'] ?? ''),
                    (string) ($row['source_symbol'] ?? ''),
                    (string) ($row['visible_control'] ?? ''),
                    (string) ($row['source_file'] ?? ''),
                ]));

                return str_contains($haystack, 'release-notes') || str_contains($haystack, 'release notes');
            })
            ->values()
            ->all();

        $this->fail('PENDING_RELEASE_NOTES_ROWS='.json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
