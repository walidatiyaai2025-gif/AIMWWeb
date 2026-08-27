<?php

namespace App\Policies;

use App\Models\TenantSecret;
use App\Models\User;
use App\Tenancy\TenantContext;

final class TenantSecretPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user, TenantSecret $secret): bool
    {
        return $this->memberCan($user, $secret, 'secrets.view');
    }

    public function update(User $user, TenantSecret $secret): bool
    {
        return $this->memberCan($user, $secret, 'secrets.manage');
    }

    private function memberCan(User $user, TenantSecret $secret, string $permission): bool
    {
        if (! $this->context->active() || $secret->tenant_id !== $this->context->id()) {
            return false;
        }

        $membership = $this->context->membership();

        return $membership->user_id === $user->id && $membership->hasPermission($permission);
    }
}
