<?php

namespace Tests\Feature;

use App\Http\Controllers\ErrorReadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErrorBackDashboardTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-85394A0E55';

    public function test_exact_canonical_operation_is_the_pending_error_back_to_dashboard_control(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/Error', $row['route_screen']);
        $this->assertSame('/ -> /', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Error.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
    }

    public function test_real_error_page_renders_the_exact_canonical_back_to_dashboard_control(): void
    {
        $this->get('/Error')
            ->assertOk()
            ->assertSee('Back to dashboard')
            ->assertSee('href="/"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('data-canonical-operation="AIMW-CONT-8B3518EF80"', false)
            ->assertDontSee('data-canonical-operation="AIMW-SYNC-89777052CB"', false);
    }

    public function test_source_and_destination_are_real_explicit_anonymous_routes_without_direct_ids(): void
    {
        $source = Route::getRoutes()->getByName('canonical.error');
        $destination = Route::getRoutes()->match(Request::create('/', 'GET'));

        $this->assertNotNull($source);
        $this->assertSame(ErrorReadController::class, $source->getActionName());
        $this->assertSame('Error', $source->uri());
        $this->assertSame([], $source->parameterNames());
        $this->assertContains('web', $source->gatherMiddleware());
        $this->assertNotContains('auth', $source->gatherMiddleware());
        $this->assertNotContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame('/', $destination->uri());
        $this->assertSame([], $destination->parameterNames());
        $this->assertContains('web', $destination->gatherMiddleware());
        $this->assertNotContains('auth', $destination->gatherMiddleware());
        $this->assertNotContains('tenant.context', $destination->gatherMiddleware());
        $this->get('/')->assertOk();
    }

    public function test_control_preserves_the_safe_error_surface_and_does_not_reflect_query_secrets(): void
    {
        $response = $this->withHeaders([
            'X-Request-ID' => 'error-back-dashboard-request-0001',
            'X-Correlation-ID' => 'error-back-dashboard-correlation-0001',
        ])->get('/Error?exception=dashboard-secret&tenant=foreign-secret');

        $response
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/"', false)
            ->assertSee('error-back-dashboard-request-0001')
            ->assertSee('error-back-dashboard-correlation-0001')
            ->assertDontSee('dashboard-secret')
            ->assertDontSee('foreign-secret')
            ->assertDontSee('/tenants/', false);
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
