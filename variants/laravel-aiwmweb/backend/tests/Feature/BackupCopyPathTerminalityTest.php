<?php

namespace Tests\Feature;

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

final class BackupCopyPathTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-141E898A90';

    public function test_canonical_operation_is_materialized_as_tenant_safe_copy_path_control(): void
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
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/backups | /module/backups', $operation['route_screen']);
        $this->assertSame('CopyPathAsync [CopyPathAsync]', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_backup_workspace_route_remains_auth_and_tenant_scoped_with_both_permissions(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/module/backups', 'GET'));

        $this->assertSame('canonical.workspace.backups', $route->getName());
        $this->assertSame('backup.manage,backups.view', $route->defaults['workspace_permissions'] ?? null);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    public function test_backup_workspace_denies_foreign_tenant_and_missing_manage_permission(): void
    {
        $this->withoutVite();

        [$alpha, $alphaUser] = $this->membership('alpha', ['backup.manage', 'backups.view']);
        [, $betaUser] = $this->membership('beta', ['backup.manage', 'backups.view']);
        [, $restrictedUser] = $this->membership('restricted', ['backups.view']);

        $this->actingAs($alphaUser)->get('/tenants/alpha/module/backups')->assertOk();
        $this->actingAs($betaUser)->get('/tenants/alpha/module/backups')->assertNotFound();
        $this->actingAs($restrictedUser)->get('/tenants/restricted/module/backups')->assertForbidden();

        app(TenantContext::class)->forget();
    }

    public function test_frontend_uses_only_server_issued_tenant_backup_endpoint_and_never_filesystem_path(): void
    {
        $appSource = (string) file_get_contents(resource_path('js/app.tsx'));
        $controlSource = (string) file_get_contents(resource_path('js/backup-copy-path-control.tsx'));

        $this->assertStringContainsString('route.key === \'backups\'', $appSource);
        $this->assertStringContainsString('BackupCopyPathControl context={context}', $appSource);
        $this->assertStringContainsString(self::OPERATION_ID, $controlSource);
        $this->assertStringContainsString('context.api.backups', $controlSource);
        $this->assertStringContainsString('hasPermission(context, \'backup.manage\')', $controlSource);
        $this->assertStringContainsString('hasPermission(context, \'backups.view\')', $controlSource);
        $this->assertStringContainsString('clipboard.writeText(value)', $controlSource);
        $this->assertStringNotContainsString('BackupDirectory', $controlSource);
        $this->assertStringNotContainsString('C:\\', $controlSource);
    }

    /** @return array{0:Tenant,1:User} */
    private function membership(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'backup-copy-path-'.$slug]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }
}
