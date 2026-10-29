<?php

namespace Tests\Feature;

use App\Http\Controllers\SecurityAuditReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SecurityAuditRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-EBD89B0642';

    public function test_canonical_row_is_the_security_audit_open_render_route(): void
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
        $this->assertSame('/admin/security-audit', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SecurityAudit.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
    }

    public function test_route_is_zero_parameter_read_only_platform_admin_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/security-audit', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertSame(SecurityAuditReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.admin.security-audit', $route->getName());
        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('platform.admin', $middleware);
        $this->assertNotContains('tenant.context', $middleware);
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->post('/admin/security-audit')->assertStatus(405);
    }

    public function test_guest_non_admin_and_platform_admin_fail_closed_or_render_authoritatively(): void
    {
        $this->get('/admin/security-audit')->assertRedirect('/login');

        $member = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($member)
            ->get('/admin/security-audit')
            ->assertForbidden();

        $admin = User::factory()->create(['platform_admin' => true]);
        $this->actingAs($admin)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertSee('Security Audit')
            ->assertSee('No matching security events.');
    }

    public function test_route_exposes_no_caller_selected_resource_identity_fields(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/security-audit')
            ->assertOk()
            ->assertDontSee('name="tenant', false)
            ->assertDontSee('name="account', false)
            ->assertDontSee('name="subscription', false)
            ->assertDontSee('name="provider', false)
            ->assertDontSee('name="user', false);
    }

    public function test_platform_admin_route_does_not_create_a_tenant_scoped_alias(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);

        $this->actingAs($admin)
            ->get('/tenants/foreign/admin/security-audit')
            ->assertNotFound();
    }
}
