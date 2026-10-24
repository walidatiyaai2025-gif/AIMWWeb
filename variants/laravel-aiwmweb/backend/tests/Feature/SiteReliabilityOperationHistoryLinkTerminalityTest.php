<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SiteReliabilityOperationHistoryLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-F4CBF0D714';

    public function test_exact_canonical_operation_matches_source_operation_history_link(): void
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
        $this->assertSame('/site-reliability | /operations/reliability', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteReliability.razor'),
        );

        $this->assertStringContainsString('<a class="btn" href="/site-operations">', $source);
        $this->assertStringContainsString('Operation history', $source);
    }

    public function test_laravel_binding_is_tenant_scoped_permission_gated_and_read_only(): void
    {
        $control = (string) file_get_contents(
            resource_path('js/site-reliability-operation-history-link-control.tsx'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("route.key === 'site-reliability'", $pages);
        $this->assertStringContainsString('SiteReliabilityOperationHistoryLinkControl context={context}', $pages);
        $this->assertStringContainsString("operationHistoryRoute.path === '/site-operations'", $control);
        $this->assertStringContainsString("operationHistoryRoute.permission === 'execution.view'", $control);
        $this->assertStringContainsString(
            'tenantUrl(context.tenant.slug, operationHistoryRoute.path)',
            $control,
        );
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_authorized_member_can_open_operation_history_and_foreign_tenant_fails_closed(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha-reliability-history', [
            'tenant.view',
            'sites.view',
            'execution.view',
        ]);

        $beta = User::factory()->create();
        $this->membership($beta, 'beta-reliability-history', [
            'tenant.view',
            'sites.view',
            'execution.view',
        ]);

        $this->actingAs($alpha)
            ->get('/tenants/alpha-reliability-history/site-operations')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta-reliability-history/site-operations')
            ->assertNotFound();
    }

    public function test_destination_requires_execution_view(): void
    {
        $this->withoutVite();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-reliability-history', [
            'tenant.view',
            'sites.view',
        ]);

        $this->actingAs($limited)
            ->get('/tenants/limited-reliability-history/site-operations')
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "site-reliability-history-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
