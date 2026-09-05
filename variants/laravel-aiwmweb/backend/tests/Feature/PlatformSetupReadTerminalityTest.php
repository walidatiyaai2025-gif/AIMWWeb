<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupReadController;
use App\Services\DatabaseSetupReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class PlatformSetupReadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-PLAT-18A8EE0324';

    public function test_canonical_setup_get_is_operation_linked_anonymous_and_resolves_to_real_controller(): void
    {
        $this->assertSame('AIMW-PLAT-18A8EE0324', self::OPERATION_ID);

        $route = Route::getRoutes()->getByName('canonical.api.setup');

        $this->assertNotNull($route);
        $this->assertSame(SetupReadController::class, $route->getActionName());
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_migrations_without_first_identity_remain_in_setup_mode(): void
    {
        $status = app(DatabaseSetupReadService::class)->status();

        $this->assertTrue($status['database_reachable']);
        $this->assertTrue($status['migrations_ready']);
        $this->assertFalse($status['identity_ready']);
        $this->assertFalse($status['complete']);

        $this->get('/setup')->assertOk()->assertSee('Identity ready:');
    }

    public function test_setup_stays_incomplete_when_any_repository_migration_is_missing(): void
    {
        DB::table('migrations')->orderByDesc('batch')->orderByDesc('migration')->limit(1)->delete();

        $status = app(DatabaseSetupReadService::class)->status();

        $this->assertTrue($status['database_reachable']);
        $this->assertFalse($status['migrations_ready']);
        $this->assertFalse($status['complete']);
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
                'identity_ready' => false,
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

    public function test_completed_setup_ignores_caller_redirect_targets_and_uses_fixed_landing_page(): void
    {
        $this->mock(DatabaseSetupReadService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->once()->andReturn([
                'complete' => true,
                'driver' => 'sqlite',
                'database_reachable' => true,
                'migrations_ready' => true,
                'identity_ready' => true,
            ]);
        });

        $this->get('/setup?returnUrl='.urlencode('https://evil.example/phish'))
            ->assertRedirect('/');
    }
}
