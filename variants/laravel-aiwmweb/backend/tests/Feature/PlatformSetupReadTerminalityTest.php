<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupReadController;
use App\Services\DatabaseSetupReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class PlatformSetupReadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_setup_get_is_anonymous_and_resolves_to_real_controller(): void
    {
        $route = Route::getRoutes()->getByName('canonical.api.setup');

        $this->assertNotNull($route);
        $this->assertSame(SetupReadController::class.'@__invoke', $route->getActionName());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_setup_redirects_to_landing_when_database_and_migrations_are_ready(): void
    {
        $status = app(DatabaseSetupReadService::class)->status();

        $this->assertTrue($status['database_reachable']);
        $this->assertTrue($status['migrations_ready']);
        $this->assertTrue($status['complete']);

        $this->get('/setup')->assertRedirect('/');
    }

    public function test_incomplete_setup_renders_authoritative_non_secret_status(): void
    {
        config(['database.connections.sqlite.password' => 'must-never-render']);

        $this->mock(DatabaseSetupReadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andReturn([
                'complete' => false,
                'driver' => 'sqlite',
                'database_reachable' => false,
                'migrations_ready' => false,
            ]);
        });

        $this->get('/setup')
            ->assertOk()
            ->assertSee('Database setup required')
            ->assertSee('Configured driver:')
            ->assertSee('sqlite')
            ->assertSee('Database reachable:')
            ->assertSee('no')
            ->assertDontSee('must-never-render');
    }
}
