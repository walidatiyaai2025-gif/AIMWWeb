<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Http\Controllers\SetupMutationController;
use App\Http\Controllers\SetupReadController;
use App\Services\DatabaseSetupPageService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ExplicitBackendProvenanceContractTest extends TestCase
{
    /**
     * @dataProvider canonicalBackendOperations
     */
    public function test_exact_pending_backend_operation_identity_is_preserved(
        string $operationId,
        string $kind,
        string $domain,
        string $routeScreen,
        string $visibleControl,
    ): void {
        $ledger = json_decode(
            file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', $operationId);

        $this->assertNotNull($row, $operationId);
        $this->assertSame($kind, $row['kind']);
        $this->assertSame($domain, $row['domain']);
        $this->assertSame($routeScreen, $row['route_screen']);
        $this->assertSame($visibleControl, $row['visible_control']);
    }

    public function test_aimw_cont_2f2e40d7f0_and_aimw_cont_270f69ce9a_bind_to_real_session_routes(): void
    {
        $login = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri() === 'api/login' && in_array('POST', $route->methods(), true),
        );
        $logout = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri() === 'api/logout' && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($login);
        $this->assertSame(DemoController::class.'@login', $login->getActionName());
        $this->assertNotContains('auth', $login->gatherMiddleware());

        $this->assertNotNull($logout);
        $this->assertSame(DemoController::class.'@logout', $logout->getActionName());
        $this->assertContains('auth', $logout->gatherMiddleware());
    }

    public function test_aimw_plat_18a8ee0324_and_aimw_cont_475267f150_bind_to_explicit_anonymous_setup_routes(): void
    {
        $read = Route::getRoutes()->getByName('canonical.api.setup');
        $write = Route::getRoutes()->getByName('canonical.api.setup.submit');

        $this->assertNotNull($read);
        $this->assertSame(SetupReadController::class, $read->getActionName());
        $this->assertContains('GET', $read->methods());
        $this->assertContains('web', $read->gatherMiddleware());
        $this->assertNotContains('auth', $read->gatherMiddleware());
        $this->assertNotContains('tenant.context', $read->gatherMiddleware());

        $this->assertNotNull($write);
        $this->assertSame(SetupMutationController::class, $write->getActionName());
        $this->assertContains('POST', $write->methods());
        $this->assertContains('web', $write->gatherMiddleware());
        $this->assertNotContains('auth', $write->gatherMiddleware());
        $this->assertNotContains('tenant.context', $write->gatherMiddleware());
    }

    public function test_aimw_cont_43af0076b5_binds_to_real_database_setup_page_service(): void
    {
        $service = app(DatabaseSetupPageService::class);

        $this->assertInstanceOf(DatabaseSetupPageService::class, $service);
        $this->assertTrue(method_exists($service, 'status'));
        $this->assertTrue(method_exists($service, 'render'));
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function canonicalBackendOperations(): array
    {
        return [
            'AIMW-CONT-2F2E40D7F0' => ['AIMW-CONT-2F2E40D7F0', 'api', 'content', '/login', 'HTTP POST /login'],
            'AIMW-CONT-270F69CE9A' => ['AIMW-CONT-270F69CE9A', 'api', 'content', '/logout', 'HTTP POST /logout'],
            'AIMW-CONT-475267F150' => ['AIMW-CONT-475267F150', 'api', 'content', '/setup', 'HTTP POST /setup'],
            'AIMW-PLAT-18A8EE0324' => ['AIMW-PLAT-18A8EE0324', 'api', 'platform', '/setup', 'HTTP GET /setup'],
            'AIMW-CONT-43AF0076B5' => ['AIMW-CONT-43AF0076B5', 'service', 'content', 'service:DatabaseSetupService', 'RenderPage'],
        ];
    }
}
