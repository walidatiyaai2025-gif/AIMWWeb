<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VerifiedRouteProvenanceContractTest extends TestCase
{
    /**
     * Exact canonical operation IDs that were already exercised by the cumulative
     * route/API closure suite but lacked an operation-ID-bearing provenance test.
     *
     * @var array<string, array{0:string,1:string}>
     */
    private const ROUTES = [
        'AIMW-CONT-4A295D45D4' => ['canonical.workspace.posts', 'tenants/{tenant}/module/posts'],
        'AIMW-CONT-EA0DDC0ABE' => ['canonical.workspace.pages', 'tenants/{tenant}/module/pages'],
        'AIMW-MEDI-BF81D0B635' => ['canonical.workspace.media', 'tenants/{tenant}/module/media'],
        'AIMW-COMM-C2DDF5DAE3' => ['canonical.workspace.comments', 'tenants/{tenant}/module/comments'],
        'AIMW-TAXO-AEEE1025B9' => ['canonical.workspace.taxonomy', 'tenants/{tenant}/module/taxonomy'],
        'AIMW-SYNC-1C799B7D70' => ['canonical.workspace.sync', 'tenants/{tenant}/module/sync'],
        'AIMW-CONT-5D18F49928' => ['canonical.workspace.reports', 'tenants/{tenant}/module/reports'],
        'AIMW-CONT-8140D785B5' => ['canonical.alias.reports', 'tenants/{tenant}/reports'],
        'AIMW-CONT-9B87A269F3' => ['canonical.workspace.site-operations', 'tenants/{tenant}/site-operations'],
        'AIMW-CONT-D76D83682F' => ['canonical.alias.operations-sites', 'tenants/{tenant}/operations/sites'],
        'AIMW-AUTO-38567579D6' => ['canonical.workspace.automation', 'tenants/{tenant}/automation-center'],
        'AIMW-AUTO-F12BC80C1B' => ['canonical.alias.automation-schedules', 'tenants/{tenant}/automation-schedules'],
        'AIMW-AUTO-1546E5BCAF' => ['canonical.workspace.schedules', 'tenants/{tenant}/module/schedules'],
        'AIMW-AUTO-6522502C20' => ['canonical.alias.execution-center', 'tenants/{tenant}/execution-center'],
        'AIMW-AUTO-968FD60A95' => ['canonical.workspace.execution', 'tenants/{tenant}/module/execution'],
        'AIMW-BILL-2FFFC55BAB' => ['canonical.workspace.account-billing', 'tenants/{tenant}/account/billing'],
    ];

    public function test_exact_canonical_ids_are_bound_to_the_existing_guarded_routes_and_closure_evidence(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/route-api-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $evidenceIds = collect($evidence['operations'] ?? [])->pluck('operation_id');

        foreach (self::ROUTES as $operationId => [$routeName, $expectedUri]) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, $operationId.' is missing its explicit named Laravel route.');
            $this->assertSame($expectedUri, $route->uri(), $operationId.' resolved to the wrong Laravel route.');
            $this->assertStringContainsString(CanonicalWorkspaceRouteController::class, $route->getActionName());
            $this->assertContains('auth', $route->gatherMiddleware(), $operationId.' lost authentication middleware.');
            $this->assertContains('tenant.context', $route->gatherMiddleware(), $operationId.' lost tenant-context middleware.');
            $this->assertNotEmpty(
                (string) ($route->defaults['workspace_permissions'] ?? ''),
                $operationId.' lost its explicit permission contract.',
            );
            $this->assertTrue(
                $evidenceIds->contains($operationId),
                $operationId.' is not linked to the cumulative route terminality evidence.',
            );
        }
    }
}
