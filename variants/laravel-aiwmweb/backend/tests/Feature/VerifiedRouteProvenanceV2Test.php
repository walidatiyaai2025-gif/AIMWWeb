<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifiedRouteProvenanceV2Test extends TestCase
{
    private const ROUTES = [
        'AIMW-CONT-4A295D45D4' => ['canonical.workspace.posts', 'tenants/{tenant}/module/posts', '/module/posts'],
        'AIMW-CONT-EA0DDC0ABE' => ['canonical.workspace.pages', 'tenants/{tenant}/module/pages', '/module/pages'],
        'AIMW-MEDI-BF81D0B635' => ['canonical.workspace.media', 'tenants/{tenant}/module/media', '/module/media'],
        'AIMW-COMM-C2DDF5DAE3' => ['canonical.workspace.comments', 'tenants/{tenant}/module/comments', '/module/comments'],
        'AIMW-TAXO-AEEE1025B9' => ['canonical.workspace.taxonomy', 'tenants/{tenant}/module/taxonomy', '/module/taxonomy'],
        'AIMW-SYNC-1C799B7D70' => ['canonical.workspace.sync', 'tenants/{tenant}/module/sync', '/module/sync'],
        'AIMW-CONT-9B87A269F3' => ['canonical.workspace.site-operations', 'tenants/{tenant}/site-operations', '/site-operations'],
        'AIMW-CONT-D76D83682F' => ['canonical.alias.operations-sites', 'tenants/{tenant}/operations/sites', '/operations/sites'],
        'AIMW-AUTO-38567579D6' => ['canonical.workspace.automation', 'tenants/{tenant}/automation-center', '/automation-center'],
        'AIMW-AUTO-F12BC80C1B' => ['canonical.alias.automation-schedules', 'tenants/{tenant}/automation-schedules', '/automation-schedules'],
        'AIMW-AUTO-1546E5BCAF' => ['canonical.workspace.schedules', 'tenants/{tenant}/module/schedules', '/module/schedules'],
        'AIMW-AUTO-6522502C20' => ['canonical.alias.execution-center', 'tenants/{tenant}/execution-center', '/execution-center'],
        'AIMW-AUTO-968FD60A95' => ['canonical.workspace.execution', 'tenants/{tenant}/module/execution', '/module/execution'],
        'AIMW-BILL-2FFFC55BAB' => ['canonical.workspace.account-billing', 'tenants/{tenant}/account/billing', '/account/billing'],
    ];

    public function test_exact_route_operations_are_linked_to_real_guarded_routes_and_terminal_evidence(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rows = collect($ledger['operations'])->keyBy('operation_id');
        $evidence = (string) file_get_contents(base_path('../docs/closure-evidence/route-api-terminality.json'));

        foreach (self::ROUTES as $operationId => [$routeName, $expectedUri, $sourceRoute]) {
            $row = $rows->get($operationId);
            $this->assertNotNull($row, "Missing canonical route row {$operationId}");
            $this->assertSame('route', $row['kind'], $operationId);
            $this->assertSame($sourceRoute, $row['route_screen'], $operationId);
            $this->assertFalse((bool) $row['mutation'], $operationId);
            $this->assertStringContainsString($operationId, $evidence, $operationId);

            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing Laravel route {$routeName}");
            $this->assertSame($expectedUri, $route->uri(), $operationId);
            $this->assertStringContainsString(
                CanonicalWorkspaceRouteController::class,
                ltrim($route->getActionName(), '\\'),
                $operationId,
            );
            $this->assertContains('auth', $route->gatherMiddleware(), $operationId);
            $this->assertContains('tenant.context', $route->gatherMiddleware(), $operationId);
        }
    }
}
