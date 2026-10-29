<?php

namespace Tests\Feature;

use App\Http\Controllers\RegisterController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RegisterRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-8DF93FC75E';

    public function test_canonical_row_is_the_register_open_render_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('/register', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Register.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
    }

    public function test_get_register_is_zero_parameter_web_only_anonymous_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/register', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertSame(RegisterController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame('register', $route->getName());
        $this->assertContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('tenant.context', $middleware);
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee('Start free trial');
    }

    public function test_anonymous_register_render_does_not_disclose_tenant_identity(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Tenant Alpha Sentinel', 'slug' => 'tenant-alpha-sentinel']);
        $beta = Tenant::query()->create(['name' => 'Tenant Beta Sentinel', 'slug' => 'tenant-beta-sentinel']);

        $anonymous = $this->get('/register');
        $anonymous->assertOk();

        $html = (string) $anonymous->getContent();
        $this->assertStringNotContainsString('Tenant Alpha Sentinel', $html);
        $this->assertStringNotContainsString('Tenant Beta Sentinel', $html);
        $this->assertStringNotContainsString('tenant-alpha-sentinel', $html);
        $this->assertStringNotContainsString('tenant-beta-sentinel', $html);
    }

    public function test_authenticated_registration_behavior_and_mutation_route_remain_distinct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/register')
            ->assertRedirect('/');

        $post = Route::getRoutes()->match(Request::create('/register', 'POST'));
        $this->assertSame(RegisterController::class.'@store', ltrim($post->getActionName(), '\\'));
        $this->assertSame(RegisterController::OPERATION_ID, $post->defaults['canonical_operation_id'] ?? null);
        $this->assertNotSame(self::OPERATION_ID, RegisterController::OPERATION_ID);
    }
}
