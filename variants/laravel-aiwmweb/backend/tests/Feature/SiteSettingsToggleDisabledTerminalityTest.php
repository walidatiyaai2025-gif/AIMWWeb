<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteSettingsToggleDisabledController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteSettingsToggleDisabledTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-84B63E3F42';

    public function test_exact_critical_canonical_operation_is_terminalized(): void
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
        $this->assertSame('/sites/{Id:guid}/settings', $operation['route_screen']);
        $this->assertStringContainsString('ToggleDisabledClicked', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor',
            $operation['current_source'],
        );
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('critical', $operation['risk']);
    }

    public function test_route_and_control_are_session_tenant_permission_csrf_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/1/settings/operational-state', 'POST'),
        );

        $this->assertSame(
            SiteSettingsToggleDisabledController::class,
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.site.settings.operational-state', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(
            'tenant.view,sites.view,sites.manage',
            $route->defaults['workspace_permissions'] ?? null,
        );
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'connected');

        $page = $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings");

        $page
            ->assertOk()
            ->assertSee('data-site-operational-state-form', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="disabled" value="1"', false)
            ->assertSee('Disable site')
            ->assertDontSee('name="tenant_id"', false)
            ->assertDontSee('name="site_id"', false)
            ->assertDontSee('name="actor_user_id"', false)
            ->assertDontSee('name="connection_status"', false);
    }

    public function test_control_is_hidden_without_manage_permission_and_direct_post_fails_closed(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'limited', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Limited Site', 'connected');

        $this->actingAs($user)
            ->get("/tenants/limited/sites/{$site->id}/settings")
            ->assertOk()
            ->assertDontSee(self::OPERATION_ID);

        $this->actingAs($user)
            ->postJson("/tenants/limited/sites/{$site->id}/settings/operational-state", ['disabled' => true])
            ->assertForbidden();

        $this->assertSame(
            'connected',
            Site::withoutGlobalScopes()->findOrFail($site->id)->connection_status,
        );
    }

    public function test_guest_foreign_tenant_foreign_site_invalid_input_and_identity_overrides_fail_before_write(): void
    {
        $this->post('/tenants/alpha/sites/1/settings/operational-state', ['disabled' => 1])
            ->assertRedirect('/login');

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'connected');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($beta, 'Beta Site', 'connected');

        $this->actingAs($alphaUser)
            ->postJson("/tenants/beta/sites/{$betaSite->id}/settings/operational-state", ['disabled' => true])
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->postJson("/tenants/alpha/sites/{$betaSite->id}/settings/operational-state", ['disabled' => true])
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->postJson("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", ['disabled' => 'toggle'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('disabled');

        $this->actingAs($alphaUser)
            ->postJson("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", [
                'disabled' => true,
                'tenant_id' => $beta->tenant_id,
                'site_id' => $betaSite->id,
                'actor_user_id' => $betaUser->id,
                'connection_status' => 'verified',
                'status' => 'disabled',
                'secret' => 'must-not-be-accepted',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame('connected', Site::withoutGlobalScopes()->findOrFail($alphaSite->id)->connection_status);
        $this->assertSame('connected', Site::withoutGlobalScopes()->findOrFail($betaSite->id)->connection_status);
    }

    public function test_disable_enable_and_replays_preserve_source_semantics_and_tenant_isolation(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'connected');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($beta, 'Beta Site', 'verified');

        $disable = $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", ['disabled' => '1']);

        $disable
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/settings")
            ->assertSessionHas('status', 'Site disabled.');

        $afterDisable = Site::withoutGlobalScopes()->findOrFail($alphaSite->id);
        $this->assertSame('disabled', $afterDisable->connection_status);
        $this->assertSame('active', $afterDisable->status);
        $this->assertSame('verified', Site::withoutGlobalScopes()->findOrFail($betaSite->id)->connection_status);

        $disableTimestamp = (string) $afterDisable->updated_at;

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", ['disabled' => '1'])
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/settings")
            ->assertSessionHas('status', 'Site disabled.');

        $afterDisableReplay = Site::withoutGlobalScopes()->findOrFail($alphaSite->id);
        $this->assertSame('disabled', $afterDisableReplay->connection_status);
        $this->assertSame($disableTimestamp, (string) $afterDisableReplay->updated_at);

        $page = $this->actingAs($user)->get("/tenants/alpha/sites/{$alphaSite->id}/settings");
        $page
            ->assertOk()
            ->assertSee('name="disabled" value="0"', false)
            ->assertSee('Enable site');

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", ['disabled' => '0'])
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/settings")
            ->assertSessionHas('status', 'Site enabled and reset for connection testing.');

        $afterEnable = Site::withoutGlobalScopes()->findOrFail($alphaSite->id);
        $this->assertSame('unknown', $afterEnable->connection_status);
        $this->assertSame('active', $afterEnable->status);
        $this->assertSame('verified', Site::withoutGlobalScopes()->findOrFail($betaSite->id)->connection_status);

        $enableTimestamp = (string) $afterEnable->updated_at;

        $this->actingAs($user)
            ->post("/tenants/alpha/sites/{$alphaSite->id}/settings/operational-state", ['disabled' => '0'])
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/settings");

        $afterEnableReplay = Site::withoutGlobalScopes()->findOrFail($alphaSite->id);
        $this->assertSame('unknown', $afterEnableReplay->connection_status);
        $this->assertSame($enableTimestamp, (string) $afterEnableReplay->updated_at);
    }

    public function test_source_and_laravel_contracts_keep_runtime_real_retry_safe_and_secret_free(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $sourceService = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Services/SiteWebService.cs'),
        );
        $controller = (string) file_get_contents(
            app_path('Http/Controllers/SiteSettingsToggleDisabledController.php'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));

        $this->assertStringContainsString('ToggleDisabledClicked', $source);
        $this->assertStringContainsString('SetDisabledAsync(Id, disable)', $source);
        $this->assertStringContainsString(
            'disabled ? SiteConnectionStatus.Disabled : SiteConnectionStatus.Unknown',
            $sourceService,
        );

        $this->assertStringContainsString("authorize('tenant.view')", $controller);
        $this->assertStringContainsString("authorize('sites.view')", $controller);
        $this->assertStringContainsString("authorize('sites.manage')", $controller);
        $this->assertStringContainsString("targetConnectionStatus = $disabled ? 'disabled' : 'unknown'", $controller);
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString('}, 3);', $controller);
        $this->assertGreaterThanOrEqual(2, substr_count($controller, 'withoutGlobalScopes()'));
        $this->assertStringContainsString('Site operational state could not be verified after persistence.', $controller);

        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringNotContainsString('api_key', strtolower($view));
        $this->assertStringNotContainsString('client_secret', strtolower($view));
        $this->assertStringNotContainsString('provider_secret', strtolower($view));
        $this->assertStringNotContainsString('dispatch(', $controller);
        $this->assertStringNotContainsString('http::', strtolower($controller));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-state-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $connectionStatus): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.str($name)->slug().'.example.test',
            'status' => 'active',
            'connection_status' => $connectionStatus,
        ]);

        $context->forget();

        return $site;
    }
}
