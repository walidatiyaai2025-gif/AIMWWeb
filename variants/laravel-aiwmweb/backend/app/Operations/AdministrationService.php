<?php

namespace App\Operations;

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdministrationService
{
    private const PROTECTED_PERMISSIONS = [
        'members.manage', 'roles.manage', 'sessions.manage', 'settings.manage',
        'operations.manage', 'backup.manage', 'reports.manage',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Redactor $redactor,
    ) {}

    public function members(): array
    {
        return TenantMembership::query()
            ->with(['user:id,name,email', 'roles:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (TenantMembership $membership) => [
                'id' => $membership->id,
                'status' => $membership->status,
                'user' => $membership->user?->only(['id', 'name', 'email']),
                'roles' => $membership->roles->map->only(['id', 'name'])->values(),
            ])->all();
    }

    public function addOrInvite(string $email, ?int $roleId, int $actorUserId): array
    {
        $email = strtolower(trim($email));
        $role = $roleId ? Role::query()->findOrFail($roleId) : null;
        $this->guardOwnerGrant($role);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            $membership = TenantMembership::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['status' => 'active'],
            );
            if ($membership->status !== 'active') {
                $membership->update(['status' => 'active']);
            }
            if ($role) {
                $membership->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $this->context->id()]]);
            }
            $this->audit($actorUserId, 'member.added', 'tenant_membership', $membership->id, ['email' => $email]);

            return ['kind' => 'member', 'membership_id' => $membership->id];
        }

        $plainToken = Str::random(48);
        $invitationId = DB::table('tenant_invitations')->insertGetId([
            'tenant_id' => $this->context->id(),
            'email' => $email,
            'invited_by_user_id' => $actorUserId,
            'role_id' => $role?->id,
            'token_hash' => hash('sha256', $plainToken),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actorUserId, 'member.invited', 'tenant_invitation', $invitationId, ['email' => $email]);

        return ['kind' => 'invitation', 'invitation_id' => $invitationId, 'token' => $plainToken, 'expires_in_days' => 7];
    }

    public function updateMember(int $membershipId, ?string $status, ?array $roleIds, int $actorUserId): array
    {
        return DB::transaction(function () use ($membershipId, $status, $roleIds, $actorUserId): array {
            $membership = TenantMembership::query()->lockForUpdate()->findOrFail($membershipId);
            $wasOwner = $this->membershipIsOwner($membership);

            if ($roleIds !== null) {
                $uniqueRoleIds = array_values(array_unique(array_map('intval', $roleIds)));
                $roles = Role::query()->whereIn('id', $uniqueRoleIds)->get();
                if ($roles->count() !== count($uniqueRoleIds)) {
                    throw (new ModelNotFoundException)->setModel(Role::class);
                }
                if ($roles->contains(fn (Role $role) => strtolower($role->name) === 'owner')) {
                    $this->guardOwnerGrant($roles->first(fn (Role $role) => strtolower($role->name) === 'owner'));
                }
                if ($wasOwner && ! $roles->contains(fn (Role $role) => strtolower($role->name) === 'owner')) {
                    $this->guardLastOwner($membership);
                }
                $membership->roles()->sync($roles->mapWithKeys(fn (Role $role) => [$role->id => ['tenant_id' => $this->context->id()]])->all());
            }

            if ($status !== null) {
                if (! in_array($status, ['active', 'inactive'], true)) {
                    throw ValidationException::withMessages(['status' => 'Status must be active or inactive.']);
                }
                if ($status === 'inactive' && $wasOwner) {
                    $this->guardLastOwner($membership);
                }
                $membership->update(['status' => $status]);
            }

            $this->audit($actorUserId, 'member.updated', 'tenant_membership', $membership->id, ['status' => $status, 'role_ids' => $roleIds]);

            return ['id' => $membership->id, 'status' => $membership->fresh()->status];
        });
    }

    public function removeMember(int $membershipId, int $actorUserId): void
    {
        DB::transaction(function () use ($membershipId, $actorUserId): void {
            $membership = TenantMembership::query()->lockForUpdate()->findOrFail($membershipId);
            if ($this->membershipIsOwner($membership)) {
                $this->guardLastOwner($membership);
            }
            $subject = $membership->id;
            $membership->delete();
            $this->audit($actorUserId, 'member.removed', 'tenant_membership', $subject, []);
        });
    }

    public function roles(): array
    {
        return Role::query()->with('permissions:id,name')->orderBy('name')->get()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
        ])->all();
    }

    public function saveRole(?int $roleId, string $name, array $permissionNames, int $actorUserId): array
    {
        $name = trim($name);
        $permissionNames = array_values(array_unique(array_map(fn ($value) => trim((string) $value), $permissionNames)));
        if ($name === '' || in_array('', $permissionNames, true)) {
            throw ValidationException::withMessages(['role' => 'Role name and permissions must be non-empty.']);
        }
        $containsProtected = count(array_intersect(self::PROTECTED_PERMISSIONS, $permissionNames)) > 0;
        if ((strtolower($name) === 'owner' || $containsProtected) && ! $this->currentMembershipIsOwner()) {
            throw new AuthorizationException('Only an owner can grant protected administration permissions.');
        }

        return DB::transaction(function () use ($roleId, $name, $permissionNames, $actorUserId): array {
            $role = $roleId ? Role::query()->findOrFail($roleId) : new Role;
            $role->name = $name;
            $role->save();

            $permissionIds = [];
            foreach ($permissionNames as $permissionName) {
                $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
                $permissionIds[$permission->id] = ['tenant_id' => $this->context->id()];
            }
            $role->permissions()->sync($permissionIds);
            $this->audit($actorUserId, 'role.saved', 'role', $role->id, ['name' => $name, 'permissions' => $permissionNames]);

            return ['id' => $role->id, 'name' => $role->name, 'permissions' => $permissionNames];
        });
    }

    public function sessions(int $actorUserId, ?string $currentSessionId): array
    {
        return DB::table('sessions')->where('user_id', $actorUserId)->orderByDesc('last_activity')->get()->map(fn ($session) => [
            'id' => $session->id,
            'current' => $currentSessionId !== null && hash_equals((string) $session->id, $currentSessionId),
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'last_activity' => $session->last_activity,
        ])->all();
    }

    public function revokeSession(string $sessionId, int $actorUserId, ?string $currentSessionId): void
    {
        if ($currentSessionId !== null && hash_equals($sessionId, $currentSessionId)) {
            throw ValidationException::withMessages(['session' => 'Use logout to revoke the current session.']);
        }
        $deleted = DB::table('sessions')->where('user_id', $actorUserId)->where('id', $sessionId)->delete();
        if ($deleted === 0) {
            throw (new ModelNotFoundException)->setModel('session');
        }
        $this->audit($actorUserId, 'session.revoked', 'session', $sessionId, []);
    }

    public function revokeOtherSessions(int $actorUserId, ?string $currentSessionId): int
    {
        $query = DB::table('sessions')->where('user_id', $actorUserId);
        if ($currentSessionId !== null) {
            $query->where('id', '!=', $currentSessionId);
        }
        $count = $query->delete();
        $this->audit($actorUserId, 'sessions.revoked_others', 'user', $actorUserId, ['count' => $count]);

        return $count;
    }

    public function settings(string $scope, ?string $siteKey, int $actorUserId): array
    {
        $query = $this->settingsQuery($scope, $siteKey, $actorUserId);

        return $query->orderBy('key')->get()->map(fn ($row) => [
            'key' => $row->key,
            'scope' => $row->scope,
            'site_key' => $row->site_key,
            'secret' => (bool) $row->is_secret,
            'value' => $row->is_secret ? '[REDACTED]' : json_decode($row->value ?? 'null', true),
        ])->all();
    }

    public function saveSetting(string $scope, string $key, mixed $value, bool $secret, ?string $siteKey, int $actorUserId): array
    {
        if (! in_array($scope, ['tenant', 'site', 'user'], true)) {
            throw ValidationException::withMessages(['scope' => 'Platform settings are read-only; writable scopes are tenant, site, and user.']);
        }
        if ($scope === 'site' && ! $siteKey) {
            throw ValidationException::withMessages(['site_key' => 'site_key is required for site settings.']);
        }
        $query = $this->settingsQuery($scope, $siteKey, $actorUserId)->where('key', $key);
        $existing = $query->first();
        $payload = [
            'tenant_id' => $this->context->id(),
            'user_id' => $scope === 'user' ? $actorUserId : null,
            'site_key' => $scope === 'site' ? $siteKey : null,
            'scope' => $scope,
            'key' => $key,
            'value' => $secret ? null : json_encode($value, JSON_THROW_ON_ERROR),
            'encrypted_value' => $secret ? Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)) : null,
            'is_secret' => $secret,
            'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('scoped_settings')->where('id', $existing->id)->update($payload);
            $id = $existing->id;
        } else {
            $payload['created_at'] = now();
            $id = DB::table('scoped_settings')->insertGetId($payload);
        }
        $this->audit($actorUserId, 'setting.saved', 'scoped_setting', $id, ['scope' => $scope, 'key' => $key, 'site_key' => $siteKey, 'secret' => $secret]);

        return ['key' => $key, 'scope' => $scope, 'site_key' => $siteKey, 'secret' => $secret, 'value' => $secret ? '[REDACTED]' : $value];
    }

    public function platformSafeSettings(): array
    {
        return [
            'app_name' => config('app.name'),
            'environment' => app()->environment(),
            'timezone' => config('app.timezone'),
            'queue_connection' => config('queue.default'),
            'session_driver' => config('session.driver'),
        ];
    }

    private function settingsQuery(string $scope, ?string $siteKey, int $actorUserId)
    {
        if (! in_array($scope, ['tenant', 'site', 'user'], true)) {
            throw ValidationException::withMessages(['scope' => 'Unsupported settings scope.']);
        }
        $query = DB::table('scoped_settings')->where('tenant_id', $this->context->id())->where('scope', $scope);
        if ($scope === 'site') {
            $query->where('site_key', $siteKey);
        } elseif ($scope === 'user') {
            $query->where('user_id', $actorUserId);
        } else {
            $query->whereNull('site_key')->whereNull('user_id');
        }

        return $query;
    }

    private function membershipIsOwner(TenantMembership $membership): bool
    {
        return $membership->roles()->whereRaw('LOWER(name) = ?', ['owner'])->exists();
    }

    private function currentMembershipIsOwner(): bool
    {
        return $this->membershipIsOwner($this->context->membership());
    }

    private function guardOwnerGrant(?Role $role): void
    {
        if ($role && strtolower($role->name) === 'owner' && ! $this->currentMembershipIsOwner()) {
            throw new AuthorizationException('Only an owner can assign the owner role.');
        }
    }

    private function guardLastOwner(TenantMembership $target): void
    {
        $otherOwners = TenantMembership::query()
            ->where('status', 'active')
            ->where('id', '!=', $target->id)
            ->whereHas('roles', fn ($query) => $query->whereRaw('LOWER(name) = ?', ['owner']))
            ->exists();
        if (! $otherOwners) {
            throw ValidationException::withMessages(['member' => 'The final active tenant owner cannot be removed, deactivated, or demoted.']);
        }
    }

    private function audit(int $actorUserId, string $event, string $subjectType, int|string $subjectId, array $metadata): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => (string) $subjectId,
            'metadata' => $this->redactor->redact($metadata),
            'occurred_at' => now(),
        ]);
    }
}
