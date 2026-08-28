<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ContentBackendTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_AIMW_CONT_2F2E40D7F0_login_authenticates_through_the_real_session_endpoint(): void
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

    public function test_AIMW_CONT_2F2E40D7F0_login_rejects_invalid_credentials_without_authenticating(): void
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

    public function test_AIMW_CONT_270F69CE9A_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertGuest();
    }
}
