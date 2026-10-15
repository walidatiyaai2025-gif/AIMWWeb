<?php

namespace Tests\Feature;

use App\Http\Controllers\RegisterController;
use App\Models\User;
use Tests\TestCase;

final class RegisterLoginNavigationTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-6E22D74EE0';

    public function test_exact_canonical_operation_is_register_to_login_navigation(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/register', $operation['route_screen']);
        $this->assertSame('/login -> /login', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Register.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_guest_register_page_exposes_exact_sign_in_destination_and_operation_marker(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('href="/login"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Sign in');

        $this->get('/login')->assertOk();
    }

    public function test_authenticated_user_cannot_use_public_register_surface(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/register')
            ->assertRedirect('/');
    }

    public function test_navigation_contract_is_static_read_only_and_distinct_from_registration_mutation(): void
    {
        $controller = (string) file_get_contents(app_path('Http/Controllers/RegisterController.php'));
        $view = (string) file_get_contents(resource_path('views/auth/register.blade.php'));

        $this->assertSame(self::OPERATION_ID, RegisterController::LOGIN_NAVIGATION_OPERATION_ID);
        $this->assertNotSame(RegisterController::OPERATION_ID, RegisterController::LOGIN_NAVIGATION_OPERATION_ID);
        $this->assertStringContainsString('href="/login"', $view);
        $this->assertStringContainsString('LOGIN_NAVIGATION_OPERATION_ID', $view);
        $this->assertStringContainsString("public const LOGIN_NAVIGATION_OPERATION_ID = '".self::OPERATION_ID."';", $controller);
        $this->assertStringNotContainsString('href="/login?tenant=', $view);
        $this->assertStringNotContainsString('href="/login?user_id=', $view);
    }
}
