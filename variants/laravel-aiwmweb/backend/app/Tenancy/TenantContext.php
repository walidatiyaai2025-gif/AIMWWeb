<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use LogicException;

final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function activate(Tenant $tenant, ?TenantMembership $membership = null): void
    {
        if ($membership !== null && $membership->tenant_id !== $tenant->id) {
            throw new LogicException('Membership does not belong to the active tenant.');
        }

        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new LogicException('Tenant context is required.');
    }

    public function id(): int
    {
        return (int) $this->tenant()->getKey();
    }

    public function membership(): TenantMembership
    {
        return $this->membership ?? throw new LogicException('Authenticated tenant membership is required.');
    }

    public function active(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }
}
