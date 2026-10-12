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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SitesSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-54E4003BE9';

    public function test_operation_and_routes_are_canonical_and_tenant_guarded(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($row);
        $this->assertSame('/sites', $row['route_screen']);
        $this->assertSame('SaveAsync [SaveAsync]', $row['visible_control']);
        $this->assertTrue((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);

        $create = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'POST'));
        $update = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7', 'PATCH'));
        $this->assertSame(self::OPERATION_ID, $create->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::OPERATION_ID, $update->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(SiteManagementController::class.'@store', ltrim($create->getActionName(), '\\'));
        $this->assertSame(SiteManagementController::class.'@update', ltrim($update->getActionName(), '\\'));
        $this->assertContains('auth', $update->gatherMiddleware());
        $this->assertContains('tenant.context', $update->gatherMiddleware());
    }

    public function test_missing_permission_and_foreign_site_fail_closed(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha-save', ['tenant.view', 'sites.view', 'sites.manage']);
        $beta = $this->membership($owner, 'beta-save', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($owner)
            ->patchJson('/api/tenants/alpha-save/sites/'.$betaSite->id, ['name' => 'Hacked', 'url' => 'https://hacked.test'])
            ->assertNotFound();
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'name' => 'Beta Site']);

        $viewer = User::factory()->create();
        $gamma = $this->membership($viewer, 'gamma-save', ['tenant.view', 'sites.view']);
        $gammaSite = $this->site($gamma, 'Gamma Site');
        $this->actingAs($viewer)
            ->patchJson('/api/tenants/gamma-save/sites/'.$gammaSite->id, ['name' => 'Denied', 'url' => 'https://denied.test'])
            ->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $gammaSite->id, 'name' => 'Gamma Site']);
    }

    public function test_authorized_create_and_update_persist_only_name_and_url(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'owner-save', ['tenant.view', 'sites.view', 'sites.manage']);

        $this->actingAs($user)->postJson('/api/tenants/owner-save/sites', [
            'name' => 'Alpha',
            'url' => 'https://alpha.test/',
            'tenant_id' => 999,
            'status' => 'disabled',
        ])->assertUnprocessable();

        $created = $this->actingAs($user)->postJson('/api/tenants/owner-save/sites', [
            'name' => 'Alpha',
            'url' => 'https://alpha.test/',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('sites', [
            'id' => $created['id'],
            'tenant_id' => $tenant->tenant_id,
            'name' => 'Alpha',
            'url' => 'https://alpha.test',
        ]);

        $this->actingAs($user)->patchJson('/api/tenants/owner-save/sites/'.$created['id'], [
            'name' => 'Alpha Updated',
            'url' => 'https://updated.test/',
            'tenant_id' => 999,
            'status' => 'disabled',
        ])->assertUnprocessable();

        $this->actingAs($user)->patchJson('/api/tenants/owner-save/sites/'.$created['id'], [
            'name' => 'Alpha Updated',
            'url' => 'https://updated.test/',
        ])->assertOk()->assertJsonPath('name', 'Alpha Updated');

        $this->assertDatabaseHas('sites', [
            'id' => $created['id'],
            'tenant_id' => $tenant->tenant_id,
            'name' => 'Alpha Updated',
            'url' => 'https://updated.test',
        ]);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();
        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test', 'status' => 'active']);
        app(TenantContext::class)->forget();
        return $site;
    }
}