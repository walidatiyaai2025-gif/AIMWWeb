<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteManagementController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteDetailsDeleteControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-BE4B8C3822';

    private const CSRF_TOKEN = 'site-delete-terminality-csrf-token';

    public function test_exact_canonical_operation_is_the_pending_confirm_delete_control(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Sites.razor', $operation['current_source']);
        $this->assertStringContainsString('Confirm delete', $operation['visible_control']);
        $this->assertTrue((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_runtime_route_is_explicit_and_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7', 'DELETE'));
        $this->assertSame(SiteManagementController::class.'@destroy', ltrim($route->getActionName(), '\\'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
    }

    public function test_authorized_delete_is_tenant_scoped_persisted_and_authoritatively_verified(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($alpha, 'Alpha Site');

        $this->csrf()->actingAs($user)->deleteJson('/api/tenants/alpha/sites/'.$site->id)->assertNoContent();
        $this->assertDatabaseMissing('sites', ['id' => $site->id, 'tenant_id' => $alpha->tenant_id]);

        $this->csrf()->actingAs($user)->deleteJson('/api/tenants/alpha/sites/'.$site->id)->assertNotFound();
    }

    public function test_missing_csrf_token_fails_closed_without_deleting_the_site(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-csrf', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($alpha, 'CSRF Protected Site');

        $this->actingAs($user)->deleteJson('/api/tenants/alpha-csrf/sites/'.$site->id)->assertStatus(419);
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'tenant_id' => $alpha->tenant_id]);
    }

    public function test_guest_permission_foreign_id_and_active_execution_fail_closed(): void
    {
        $this->deleteJson('/api/tenants/alpha/sites/1')->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->csrf()->actingAs($limited)->deleteJson('/api/tenants/limited/sites/'.$limitedSite->id)->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $limitedSite->id]);

        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $beta = $this->membership($owner, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        $this->csrf()->actingAs($owner)->deleteJson('/api/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();
        $this->csrf()->actingAs($owner)->deleteJson('/api/tenants/alpha/sites/not-a-number')->assertNotFound();
        $this->csrf()->actingAs($owner)->deleteJson('/api/tenants/alpha/sites/0')->assertNotFound();
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id]);

        DB::table('executions')->insert([
            'operation_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'tenant_id' => $alpha->tenant_id,
            'site_id' => $alphaSite->id,
            'approval_id' => 999999,
            'actor_user_id' => $owner->id,
            'status' => 'running',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->csrf()->actingAs($owner)->deleteJson('/api/tenants/alpha/sites/'.$alphaSite->id)->assertConflict();
        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id]);
    }

    private function csrf(): static
    {
        return $this->withSession(['_token' => self::CSRF_TOKEN])
            ->withHeader('X-CSRF-TOKEN', self::CSRF_TOKEN);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-delete-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://example.test/'.strtolower(str_replace(' ', '-', $name)), 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
