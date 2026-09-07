<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ContentBackendTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_OPERATION_ID = 'AIMW-CONT-2F2E40D7F0';

    public function test_login_operation_is_bound_to_anonymous_pre_tenant_session_route(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($candidate): bool => $candidate->uri() === 'api/login' && in_array('POST', $candidate->methods(), true)
        );

        $this->assertNotNull($route, self::LOGIN_OPERATION_ID);
        $this->assertSame(DemoController::class.'@login', $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_aimw_cont_2f2e40d7f0_login_authenticates_through_the_real_session_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'content-terminality@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
        $this->assertAuthenticatedAs($user);
    }

    public function test_aimw_cont_2f2e40d7f0_login_rejects_invalid_credentials_without_authenticating(): void
    {
        $user = User::factory()->create([
            'email' => 'content-terminality-invalid@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_aimw_cont_270f69ce9a_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertGuest();
    }
}
