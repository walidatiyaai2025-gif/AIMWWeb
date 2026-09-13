<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CurrentUserSignOutTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-IDEN-BF78057C28';

    public function test_exact_canonical_operation_is_the_pending_current_user_sign_out_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('identity', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertSame('button control 10', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('none', $operation['external_dependency']);
    }

    public function test_logout_reuses_the_real_session_route_and_is_auth_guarded_without_tenant_impersonation(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/logout', 'POST'));

        $this->assertSame(
            DemoController::class.'@logout',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertContains('POST', $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_foreign_tenant_qualified_logout_surface_is_not_exposed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/tenants/foreign-tenant/logout')->assertNotFound();
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_logout_invalidates_the_current_session_and_rotates_the_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $controller = (string) file_get_contents(app_path('Http/Controllers/DemoController.php'));
        $this->assertStringContainsString('Auth::logout();', $controller);
        $this->assertStringContainsString('$request->session()->invalidate();', $controller);
        $this->assertStringContainsString('$request->session()->regenerateToken();', $controller);

        $response = $this->withSession(['logout_probe' => 'present'])
            ->postJson('/api/logout');

        $response->assertOk()->assertJsonPath('ok', true);
        $response->assertSessionMissing('logout_probe');
        $this->assertGuest();
    }

    public function test_guest_cannot_invoke_the_session_logout_endpoint(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_runtime_binding_uses_the_exact_operation_marker_real_endpoint_and_csrf_aware_client(): void
    {
        $runtime = (string) file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $control = (string) file_get_contents(resource_path('js/current-user-sign-out-control.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString("import { CurrentUserSignOutControl } from './current-user-sign-out-control';", $runtime);
        $this->assertStringContainsString('<CurrentUserSignOutControl />', $runtime);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("apiRequest<LogoutResponse>('/api/logout', { method: 'POST' })", $control);
        $this->assertStringContainsString("document.querySelector<HTMLElement>('.topbar-actions')", $control);
        $this->assertStringContainsString('meta[name="csrf-token"]', $core);
        $this->assertStringContainsString("headers.set('X-CSRF-TOKEN', csrf)", $core);
        $this->assertStringContainsString("credentials: 'same-origin'", $core);
    }
}
