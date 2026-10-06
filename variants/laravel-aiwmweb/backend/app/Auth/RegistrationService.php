<?php

namespace App\Auth;

use App\Billing\SubscriptionService;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegistrationService
{
    /** @var list<string> */
    private const OWNER_PERMISSIONS = [
        'tenant.view',
        'sites.view',
        'sites.manage',
        'connector.manage',
        'ai.use',
        'ai.manage',
        'seo.manage',
        'approvals.manage',
        'executions.manage',
        'execution.view',
        'operations.manage',
        'settings.manage',
        'billing.view',
        'billing.manage',
        'automation.manage',
        'notifications.manage',
        'email.manage',
        'reports.view',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Create the durable account/workspace boundary first.
     *
     * @return array{user: User, tenant: Tenant, membership: TenantMembership}
     */
    public function register(string $userName, string $password): array
    {
        $cleanUserName = trim($userName);
        $normalized = strtoupper($cleanUserName);

        try {
            return DB::transaction(function () use ($cleanUserName, $normalized, $password): array {
                if (User::query()->where('normalized_username', $normalized)->exists()) {
                    throw ValidationException::withMessages([
                        'username' => 'This username is already registered.',
                    ]);
                }

                $user = User::query()->create([
                    'name' => $cleanUserName,
                    'username' => $cleanUserName,
                    'normalized_username' => $normalized,
                    'email' => $this->internalEmail($normalized),
                    'password' => $password,
                ]);

                $tenant = Tenant::query()->create([
                    'name' => $cleanUserName."'s Workspace",
                    'slug' => $this->workspaceSlug($cleanUserName, $normalized),
                ]);

                $this->context->activate($tenant);

                try {
                    $membership = TenantMembership::query()->create([
                        'user_id' => $user->id,
                        'status' => 'active',
                    ]);

                    $this->context->activate($tenant, $membership);
                    $role = Role::query()->create(['name' => 'owner']);

                    foreach (self::OWNER_PERMISSIONS as $permissionName) {
                        $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
                        $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
                    }

                    $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

                    AuditEvent::query()->create([
                        'actor_user_id' => $user->id,
                        'event' => 'account.registered',
                        'subject_type' => 'user',
                        'subject_id' => (string) $user->id,
                        'metadata' => ['username' => $cleanUserName],
                        'occurred_at' => now(),
                    ]);
                } finally {
                    $this->context->forget();
                }

                return [
                    'user' => $user,
                    'tenant' => $tenant,
                    'membership' => $membership,
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (User::query()->where('normalized_username', $normalized)->exists()) {
                throw ValidationException::withMessages([
                    'username' => 'This username is already registered.',
                ]);
            }

            throw $exception;
        }
    }

    public function startFreeTrial(Tenant $tenant, TenantMembership $membership): TenantSubscription
    {
        $this->context->activate($tenant, $membership);

        try {
            return $this->subscriptions->startTrial();
        } finally {
            $this->context->forget();
        }
    }

    private function workspaceSlug(string $userName, string $normalized): string
    {
        $base = Str::slug($userName);
        $base = $base !== '' ? Str::limit($base, 40, '') : 'workspace';

        return $base.'-'.substr(hash('sha256', $normalized), 0, 10);
    }

    private function internalEmail(string $normalized): string
    {
        return 'local+'.substr(hash('sha256', $normalized), 0, 40).'@accounts.invalid';
    }
}
