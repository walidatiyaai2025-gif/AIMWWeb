<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BillingEmailSettingsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-092F59830B';

    public function test_exact_canonical_operation_is_the_adapted_billing_email_settings_visible_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/account/billing', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccountBilling.razor', $operation['current_source']);
        $this->assertStringContainsString('/account/email-settings', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_destination_are_explicit_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/account/billing', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/account/email-settings', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('billing.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('tenant.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertSame('AIMW-EMAI-B2CFCF818C', $destination->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame(['tenant'], $source->parameterNames());
        $this->assertSame(['tenant'], $destination->parameterNames());
    }

    public function test_authorized_user_reaches_real_destination_and_authoritative_configuration_read(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'billing.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/account/billing')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/account/email-settings')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/configuration')
            ->assertOk()
            ->assertJsonPath('configuration_key', 'default')
            ->assertJsonPath('configured', false)
            ->assertJsonPath('has_secret', false)
            ->assertJsonMissingPath('secret');
    }

    public function test_runtime_binding_uses_authoritative_tenant_and_read_only_email_configuration(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/billing-profile-link.tsx'));
        $summary = (string) file_get_contents(resource_path('js/account-email-settings-summary.tsx'));
        $service = (string) file_get_contents(app_path('Email/Services/MailConfigurationService.php'));
        $serialize = explode('public function serialize', $service, 2)[1] ?? '';

        $this->assertStringContainsString("route.key === 'account-billing'", $app);
        $this->assertStringContainsString('path="account/email-settings"', $app);
        $this->assertStringContainsString('<AccountEmailSettingsRoute />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/account/email-settings')", $control);
        $this->assertStringContainsString("permissions.includes('tenant.manage')", $summary);
        $this->assertStringContainsString('encodeURIComponent(context.tenant.slug)', $summary);
        $this->assertStringContainsString('/email/configuration', $summary);
        $this->assertStringNotContainsString('apiRequest<MailConfigurationSummary>(endpoint, {', $summary);
        $this->assertStringContainsString("'has_secret' =>", $serialize);
        $this->assertStringNotContainsString("'secret' =>", $serialize);
    }

    public function test_guest_missing_permissions_and_foreign_tenant_paths_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/account/billing')->assertRedirect('/login');
        $this->get('/tenants/alpha/account/email-settings')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/account/billing')->assertForbidden();

        $billingViewer = User::factory()->create();
        $this->membership($billingViewer, 'viewer', ['tenant.view', 'billing.view']);
        $this->actingAs($billingViewer)->get('/tenants/viewer/account/billing')->assertOk();
        $this->actingAs($billingViewer)->get('/tenants/viewer/account/email-settings')->assertOk();
        $this->actingAs($billingViewer)->getJson('/api/v1/tenants/viewer/email/configuration')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'tenant.manage', 'billing.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'tenant.manage', 'billing.view']);

        $this->actingAs($alpha)->get('/tenants/beta/account/billing')->assertNotFound();
        $this->actingAs($alpha)->get('/tenants/beta/account/email-settings')->assertNotFound();
        $this->actingAs($alpha)->getJson('/api/v1/tenants/beta/email/configuration')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "billing-email-settings-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
