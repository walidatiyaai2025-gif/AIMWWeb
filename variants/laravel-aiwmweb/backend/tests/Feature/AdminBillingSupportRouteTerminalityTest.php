<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminBillingSupportReadController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminBillingSupportRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-5811B45F89';

    public function test_exact_canonical_input_is_the_pending_billing_support_route(): void
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
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/admin/billing-support', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AdminBillingSupport.razor', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned'], 'The generated canonical ledger conservatively classifies this billing operation as tenant-owned.');
    }

    public function test_route_is_exact_read_only_global_admin_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/billing-support', 'GET'));

        $this->assertSame(AdminBillingSupportReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('canonical.admin.billing-support', $route->getName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('platform.admin', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
    }

    public function test_guest_and_non_platform_admin_fail_closed(): void
    {
        $this->get('/admin/billing-support')->assertRedirect('/login');

        $user = User::factory()->create();
        $user->forceFill(['platform_admin' => false])->save();

        $this->actingAs($user)
            ->get('/admin/billing-support')
            ->assertForbidden();
    }

    public function test_platform_admin_renders_real_read_only_support_surface_without_resource_ids_or_actions(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['platform_admin' => true])->save();
        $before = $admin->fresh()->toArray();
        $this->withoutVite();

        $this->actingAs($admin)
            ->get('/admin/billing-support')
            ->assertOk()
            ->assertSee('Billing support')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('<form', false)
            ->assertDontSee('<button', false)
            ->assertDontSee('name="tenant', false)
            ->assertDontSee('name="subscription', false)
            ->assertDontSee('name="provider', false);

        $this->assertSame($before, $admin->fresh()->toArray(), 'Opening the support route must not mutate authoritative user state.');
    }

    public function test_source_and_laravel_authorization_contracts_remain_fail_closed(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AdminBillingSupport.razor'));
        $provider = (string) file_get_contents(app_path('Providers/AdminBillingSupportRouteServiceProvider.php'));
        $middleware = (string) file_get_contents(app_path('Http/Middleware/RequirePlatformAdmin.php'));
        $view = (string) file_get_contents(resource_path('views/billing/admin-support.blade.php'));

        $this->assertStringContainsString('@page "/admin/billing-support"', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.SettingsManage', $source);
        $this->assertStringContainsString("['web', 'auth', 'platform.admin']", $provider);
        $this->assertStringContainsString('canonical_operation_id', $provider);
        $this->assertStringContainsString('$request->user()?->platform_admin', $middleware);
        $this->assertStringContainsString('Browser returns and caller-supplied identifiers are not treated as payment success.', $view);
        $this->assertStringNotContainsString('PayPalClientId', $view);
        $this->assertStringNotContainsString('PayPalClientSecret', $view);
        $this->assertStringNotContainsString('apiRequest', $view);
    }
}
