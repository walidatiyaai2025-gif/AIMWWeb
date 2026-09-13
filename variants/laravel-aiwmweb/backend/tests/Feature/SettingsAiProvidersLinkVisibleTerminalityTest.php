<?php

namespace Tests\Feature;

use App\Http\Controllers\AiProviderSettingsReadController;
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

final class SettingsAiProvidersLinkVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-8205320842';

    public function test_exact_canonical_operation_is_generator_backed_adapted_settings_navigation(): void
    {
        $ledger = $this->reconciliation();
        $manifest = $this->manifest();
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $frontend = file_get_contents(resource_path('js/settings-ai-providers-link-control.tsx'));

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/settings', $operation['route_screen']);
        $this->assertStringContainsString('/settings/ai-providers', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Settings.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode']);
        $this->assertSame(
            $manifest['focused_closure_evidence_source_sha'],
            $operation['reconciliation']['source_sha'],
        );
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/settings-ai-providers-link-terminality.json',
            $operation['reconciliation']['evidence_path'],
        );
        $this->assertContains(self::OPERATION_ID, $ledger['validation']['focused_closure_contract_terminals']);
        $this->assertSame(931, $ledger['totals']['total']);
        $this->assertSame(
            $ledger['totals']['total'],
            $ledger['totals']['terminal'] + $ledger['totals']['pending'] + $ledger['totals']['blocked'],
        );
        $this->assertTrue((bool) ($ledger['validation']['passed'] ?? false));

        $this->assertIsString($frontend);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("context.permissions.includes('settings.manage')", $frontend);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/settings/ai-providers')", $frontend);
    }

    public function test_destination_is_the_existing_guarded_tenant_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-providers', 'GET'));

        $this->assertSame(AiProviderSettingsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('tenant.settings.ai-providers', $route->getName());
        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->get('/tenants/alpha/settings/ai-providers')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/settings/ai-providers')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['settings.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);

        $this->actingAs($alpha)->get('/tenants/beta/settings/ai-providers')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-evidence-sources.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "settings-ai-providers-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
