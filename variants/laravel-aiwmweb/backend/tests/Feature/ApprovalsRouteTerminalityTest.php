<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApprovalsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-APPR-A292974395';

    private const LOAD_OPERATION_ID = 'AIMW-APPR-31A36E339F';

    private const EXECUTION_LINK_OPERATION_ID = 'AIMW-APPR-B360D1C8BA';

    public function test_canonical_row_is_the_pending_approvals_route(): void
    {
        $row = $this->canonicalRow(self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('approvals', $row['domain']);
        $this->assertSame('/approvals', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ApprovalQueue.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_approvals_alias_is_explicit_guarded_and_targets_the_real_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/approvals', 'GET'));

        $this->assertSame('canonical.alias.approvals', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@redirect', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,approvals.view', $route->defaults['workspace_permissions']);
        $this->assertSame('/module/approvals', $route->defaults['workspace_target']);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation']);
    }

    public function test_authorized_user_reaches_real_workspace_and_authoritative_persisted_queue(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'approvals.view']);
        $approval = $this->seedApproval($membership, 'PENDING');

        $this->actingAs($user)->get('/tenants/alpha/approvals')
            ->assertRedirect('/tenants/alpha/module/approvals');

        $registry = file_get_contents(resource_path('js/core.ts'));
        $this->assertStringContainsString("r('approvals', '/module/approvals'", $registry);
        $this->assertStringContainsString("permission: 'approvals.view'", $registry);

        $app = file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString('function ApprovalQueueRoute', $app);
        $this->assertStringContainsString("route.key === 'approvals'", $app);
        $this->assertStringContainsString('withApprovalQueueEndpoint(query.data)', $app);
        $this->assertStringContainsString('data-canonical-operation="'.self::LOAD_OPERATION_ID.'"', $app);
        $this->assertStringContainsString('data-canonical-operation="'.self::EXECUTION_LINK_OPERATION_ID.'"', $app);

        $endpoint = file_get_contents(resource_path('js/approvalQueue.ts'));
        $this->assertStringContainsString('approvals: `/api/tenants/${encodeURIComponent(context.tenant.slug)}/approvals`', $endpoint);

        $this->actingAs($user)->getJson('/api/tenants/alpha/approvals')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $approval->id)
            ->assertJsonPath('data.0.status', 'PENDING');
    }

    public function test_guest_missing_permission_and_foreign_tenant_access_fail_closed(): void
    {
        $foreign = Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->getJson('/tenants/'.$foreign->slug.'/approvals')->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->get('/tenants/limited/approvals')->assertForbidden();
        $this->actingAs($limited)->getJson('/api/tenants/limited/approvals')->assertForbidden();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha-only', ['tenant.view', 'approvals.view']);
        $this->actingAs($alphaUser)->get('/tenants/foreign/approvals')->assertNotFound();
        $this->actingAs($alphaUser)->getJson('/api/tenants/foreign/approvals')->assertNotFound();
    }

    public function test_empty_queue_is_truthful_and_never_falls_back_to_demo_success(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'empty', ['tenant.view', 'approvals.view']);

        $this->actingAs($user)->get('/tenants/empty/approvals')
            ->assertRedirect('/tenants/empty/module/approvals');

        $this->actingAs($user)->getJson('/api/tenants/empty/approvals')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    private function canonicalRow(string $operationId): ?array
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($payload['operations'])->firstWhere('operation_id', $operationId);
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
        $role = Role::query()->create(['name' => "approvals-route-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function seedApproval(TenantMembership $membership, string $status): Approval
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $site = Site::query()->create([
            'name' => $membership->tenant->name.' Approval Site',
            'url' => 'https://'.$membership->tenant->slug.'-approval.test',
        ]);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => random_int(1000, 999999),
            'slug' => 'approvals-route-'.$site->id,
            'title' => 'Approval route candidate',
        ]);
        $audit = SeoAudit::query()->create([
            'site_id' => $site->id,
            'actor_user_id' => $membership->user_id,
        ]);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'approvals_route_test',
            'severity' => 'medium',
            'recommendation' => 'Review this change',
        ]);
        $suggestion = Suggestion::query()->create([
            'site_id' => $site->id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $membership->user_id,
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
            'status' => 'succeeded',
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $membership->user_id,
            'status' => $status,
            'before_state' => ['title' => 'Before'],
            'proposed_state' => ['title' => 'After'],
            'decided_at' => $status === 'PENDING' ? null : now(),
        ]);

        $context->forget();

        return $approval;
    }
}
