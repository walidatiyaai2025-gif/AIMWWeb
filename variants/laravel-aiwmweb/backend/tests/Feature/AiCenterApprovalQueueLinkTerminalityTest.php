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

final class AiCenterApprovalQueueLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-991683D92C';

    public function test_exact_canonical_row_is_the_pending_ai_center_approval_queue_navigation(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('/ai-center', $row['route_screen']);
        $this->assertStringContainsString('/approvals', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AICenter.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
    }

    public function test_frontend_control_is_wired_to_the_real_guarded_approval_alias_only(): void
    {
        $control = file_get_contents(resource_path('js/ai-center-approval-queue-link.tsx'));
        $host = file_get_contents(resource_path('js/ai-center-approval-status-control.tsx'));
        $app = file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/approvals')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.use')", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('approvals.view')", $control);
        $this->assertStringContainsString('AiCenterApprovalQueueLink', $host);
        $this->assertStringContainsString("route.key === 'ai-center'", $app);
        $this->assertStringNotContainsString('AIMW-AI-331ED9D5EE', $control);
        $this->assertStringNotContainsString('AIMW-AI-93EBFDE5A1', $control);
        $this->assertStringNotContainsString('AIMW-AI-DDB072FE15', $control);
    }

    public function test_destination_is_the_existing_explicit_guarded_approval_queue_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/approvals', 'GET'));

        $this->assertSame('canonical.alias.approvals', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@redirect', ltrim($route->getActionName(), '\\'));
        $this->assertContains('GET', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,approvals.view', $route->defaults['workspace_permissions']);
        $this->assertSame('/module/approvals', $route->defaults['workspace_target']);
        $this->assertSame('AIMW-APPR-A292974395', $route->defaults['canonical_operation_id']);
    }

    public function test_authorized_user_reaches_the_real_tenant_approval_queue_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'ai.use', 'approvals.view']);

        $this->actingAs($user)->get('/tenants/alpha/approvals')
            ->assertRedirect('/tenants/alpha/module/approvals');

        $this->actingAs($user)->getJson('/api/tenants/alpha/approvals')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_missing_permission_and_foreign_tenant_access_fail_closed(): void
    {
        $this->getJson('/tenants/alpha/approvals')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view', 'ai.use']);
        $this->actingAs($limited)->get('/tenants/limited/approvals')->assertForbidden();
        $this->actingAs($limited)->getJson('/api/tenants/limited/approvals')->assertForbidden();

        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha-only', ['tenant.view', 'ai.use', 'approvals.view']);
        $this->actingAs($alpha)->get('/tenants/foreign/approvals')->assertNotFound();
        $this->actingAs($alpha)->getJson('/api/tenants/foreign/approvals')->assertNotFound();
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
        $role = Role::query()->create(['name' => "ai-center-approval-link-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
