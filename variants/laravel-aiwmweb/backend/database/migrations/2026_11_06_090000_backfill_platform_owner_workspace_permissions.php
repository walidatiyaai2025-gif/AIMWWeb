<?php

use App\Auth\OwnerPermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill only the verified first-install platform Owner role(s), including
     * React-only permissions such as ai.configure. Never grant other memberships,
     * wildcard rights, or WordPress Connector capabilities.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $owners = DB::table('roles')
                ->join('role_tenant_membership', function ($join): void {
                    $join->on('role_tenant_membership.role_id', '=', 'roles.id')
                        ->on('role_tenant_membership.tenant_id', '=', 'roles.tenant_id');
                })
                ->join('tenant_memberships', function ($join): void {
                    $join->on('tenant_memberships.id', '=', 'role_tenant_membership.tenant_membership_id')
                        ->on('tenant_memberships.tenant_id', '=', 'roles.tenant_id');
                })
                ->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->whereRaw('LOWER(roles.name) = ?', ['owner'])
                ->where('tenant_memberships.status', 'active')
                ->where('users.platform_admin', true)
                ->distinct()
                ->get(['roles.id as role_id', 'roles.tenant_id']);

            $catalog = app(OwnerPermissionCatalog::class)->all();

            foreach ($owners as $owner) {
                $tenantId = (int) $owner->tenant_id;
                foreach ($catalog as $name) {
                    $permissionId = DB::table('permissions')
                        ->where('tenant_id', $tenantId)
                        ->where('name', $name)
                        ->value('id');

                    if ($permissionId === null) {
                        $permissionId = DB::table('permissions')->insertGetId([
                            'tenant_id' => $tenantId,
                            'name' => $name,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('permission_role')->updateOrInsert([
                        'tenant_id' => $tenantId,
                        'permission_id' => (int) $permissionId,
                        'role_id' => (int) $owner->role_id,
                    ]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Intentionally forward-only: never revoke current production permissions
        // during a rollback. Restore verified DB backup if recovery requires it.
    }
};
