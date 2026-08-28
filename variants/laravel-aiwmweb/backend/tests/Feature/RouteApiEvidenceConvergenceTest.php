<?php

namespace Tests\Feature;

use Tests\TestCase;

class RouteApiEvidenceConvergenceTest extends TestCase
{
    private const IMPLEMENTATION_SNAPSHOT = '83689007f252a96525b8eb735bb9a93160219e05';

    private const TENANTLESS_CANONICAL_APIS = [
        'AIMW-PLAT-A91A2B0B11' => '/api/build',
        'AIMW-PLAT-FAC7505B26' => '/api/dashboard',
    ];

    private const CLAIMED_OPERATION_IDS = [
        'AIMW-CONT-D828690844',
        'AIMW-EMAI-2E95AF6C05',
        'AIMW-EMAI-78352CD34E',
        'AIMW-BACK-66BFA49775',
        'AIMW-CONT-DF483546DA',
        'AIMW-CONT-FBD0368CAA',
        'AIMW-CONT-BB5B32880A',
        'AIMW-CONT-1DA83B9262',
        'AIMW-CONT-9B8574AA90',
        'AIMW-CONT-E14274269E',
        'AIMW-BACK-979DEF54FA',
        'AIMW-CONT-4A295D45D4',
        'AIMW-CONT-EA0DDC0ABE',
        'AIMW-MEDI-BF81D0B635',
        'AIMW-COMM-C2DDF5DAE3',
        'AIMW-TAXO-AEEE1025B9',
        'AIMW-SYNC-1C799B7D70',
        'AIMW-COMM-A16719E105',
        'AIMW-MEDI-8BADBE1261',
        'AIMW-TAXO-CDC6948A06',
        'AIMW-CONT-5D18F49928',
        'AIMW-CONT-8140D785B5',
        'AIMW-CONT-9B87A269F3',
        'AIMW-CONT-D76D83682F',
        'AIMW-AUTO-38567579D6',
        'AIMW-AUTO-F12BC80C1B',
        'AIMW-AUTO-1546E5BCAF',
        'AIMW-AUTO-6522502C20',
        'AIMW-AUTO-968FD60A95',
        'AIMW-BILL-2FFFC55BAB',
        'AIMW-CONT-FB7F9189C0',
        'AIMW-PLAT-A91A2B0B11',
        'AIMW-PLAT-FAC7505B26',
    ];

    public function test_route_api_evidence_matches_the_live_33_operation_implementation_snapshot(): void
    {
        $evidence = $this->evidence();

        $this->assertSame(2, $evidence['schema_version']);
        $this->assertSame(self::IMPLEMENTATION_SNAPSHOT, $evidence['implementation_snapshot_sha']);
        $this->assertSame(92, $evidence['inventory']['pending_route_api_rows_found']);
        $this->assertSame(84, $evidence['inventory']['pending_routes_found']);
        $this->assertSame(8, $evidence['inventory']['pending_apis_found']);
        $this->assertSame(33, $evidence['inventory']['terminalized_by_implementation_snapshot']);
        $this->assertSame(59, $evidence['inventory']['still_pending_after_this_snapshot']);

        $claimed = array_column($evidence['operations'], 'operation_id');
        $this->assertSame(self::CLAIMED_OPERATION_IDS, $claimed);
        $this->assertCount(33, $claimed);
        $this->assertCount(33, array_unique($claimed));
        $this->assertSame(33, array_sum($evidence['terminalized_by_domain']));
    }

    public function test_claimed_operations_are_not_reintroduced_as_blockers(): void
    {
        $evidence = $this->evidence();
        $blockerIds = [];

        foreach ($evidence['remaining_blockers'] as $value) {
            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $operationId) {
                if (is_string($operationId)) {
                    $blockerIds[] = $operationId;
                }
            }
        }

        $this->assertSame([], array_values(array_intersect(self::CLAIMED_OPERATION_IDS, $blockerIds)));
    }

    public function test_every_claim_has_a_canonical_route_and_non_placeholder_proof(): void
    {
        foreach ($this->evidence()['operations'] as $operation) {
            $this->assertNotEmpty($operation['source_route']);
            $canonicalRoute = $operation['canonical_route'];
            $tenantless = self::TENANTLESS_CANONICAL_APIS[$operation['operation_id']] ?? null;
            if ($tenantless !== null) {
                $this->assertSame($tenantless, $canonicalRoute);
            } else {
                $this->assertStringStartsWith('/tenants/{tenant}/', $canonicalRoute);
            }
            $this->assertNotEmpty($operation['proof']);
            $this->assertStringNotContainsString('SPA catch-all', $operation['proof']);
            $this->assertStringNotContainsString('placeholder', strtolower($operation['proof']));
        }
    }

    private function evidence(): array
    {
        $path = base_path('../docs/closure-evidence/route-api-terminality.json');
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
