<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first installer owner got permissions discovered only from app/ PHP.
     * Route-only workspace permissions were omitted. Correct solely bootstrap
     * Owner roles actually assigned to active platform administrators.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $bootstrapRoles = DB::table('roles')
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

            foreach ($bootstrapRoles as $role) {
                $tenantId = (int) $role->tenant_id;
                foreach (['sync.view', 'backups.view', 'content.view', 'content.edit'] as $name) {
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
                        'role_id' => (int) $role->role_id,
                    ]);
                }
            }
        }, 3);
    }

    public function down(): void
    {
        // Forward-only RBAC correction. Never revoke a legitimate administrator's
        // permissions on rollback: historical grants cannot be distinguished.
    }
};
