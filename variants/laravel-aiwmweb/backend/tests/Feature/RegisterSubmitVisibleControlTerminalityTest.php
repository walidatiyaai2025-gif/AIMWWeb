<?php

namespace Tests\Feature;

use App\Http\Controllers\RegisterController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RegisterSubmitVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-7D7BCD462F';

    public function test_exact_canonical_operation_is_register_button_control_two(): void
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
        $this->assertSame('button control 2', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Register.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_button_semantics_are_preserved_without_claiming_register_async(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Register.razor'));
        $view = (string) file_get_contents(resource_path('views/auth/register.blade.php'));

        $this->assertStringContainsString('<button class="auth-submit" type="submit" disabled="@_working">', $source);
        $this->assertStringContainsString('Start free trial', $source);
        $this->assertStringContainsString('OnValidSubmit="RegisterAsync"', $source);

        $this->assertSame(self::OPERATION_ID, RegisterController::REGISTER_SUBMIT_VISIBLE_OPERATION_ID);
        $this->assertNotSame(RegisterController::OPERATION_ID, RegisterController::REGISTER_SUBMIT_VISIBLE_OPERATION_ID);
        $this->assertNotSame(RegisterController::LOGIN_NAVIGATION_OPERATION_ID, RegisterController::REGISTER_SUBMIT_VISIBLE_OPERATION_ID);

        $this->assertStringContainsString('type="submit"', $view);
        $this->assertStringContainsString('REGISTER_SUBMIT_VISIBLE_OPERATION_ID', $view);
        $this->assertStringContainsString('data-canonical-operation="{{ \\App\\Http\\Controllers\\RegisterController::OPERATION_ID }}"', $view);
    }

    public function test_guest_register_page_renders_the_exact_real_submit_control(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('type="submit"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Start free trial');
    }

    public function test_visible_control_adds_no_duplicate_route_or_mutation_contract(): void
    {
        $view = (string) file_get_contents(resource_path('views/auth/register.blade.php'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/RegisterController.php'));

        $this->assertSame(1, substr_count($view, '<form method="post" action="/register"'));
        $this->assertSame(1, substr_count($view, '<button type="submit"'));
        $this->assertStringContainsString("public const OPERATION_ID = 'AIMW-BILL-34B7686F5E';", $controller);
        $this->assertStringContainsString("public const REGISTER_SUBMIT_VISIBLE_OPERATION_ID = '".self::OPERATION_ID."';", $controller);
    }

    public function test_public_surface_cannot_be_rebound_to_a_tenant_and_authenticated_users_still_leave_register(): void
    {
        $this->get('/tenants/foreign/register')->assertNotFound();

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/register')
            ->assertRedirect('/');
    }
}
