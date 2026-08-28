<?php

namespace App\Billing;

use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\QuotaExceededException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Canonical account entitlement enforcement adapted to tenant-scoped billing.
 * Checks never consume quota; the consuming operation remains responsible for
 * atomic usage mutation through UsageQuotaService.
 */
final class AccountEntitlementEnforcementService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function requireBooleanCapabilityAsync(string $entitlementKey): void
    {
        if ($this->platformAdministrator()) {
            return;
        }

        $this->entitlements->assert($entitlementKey);
    }

    public function requireAdditionalUsageAsync(
        string $entitlementKey,
        int $currentUsage,
        int $requestedAdditional = 1,
    ): void {
        if ($currentUsage < 0) {
            throw new InvalidArgumentException('Current usage cannot be negative.');
        }
        if ($requestedAdditional <= 0) {
            throw new InvalidArgumentException('Requested additional usage must be greater than zero.');
        }
        if ($this->platformAdministrator()) {
            return;
        }

        $limit = $this->entitlements->limit($entitlementKey);
        if ($limit === null) {
            throw new EntitlementDeniedException("Subscription limit is not configured: {$entitlementKey}");
        }
        if ($currentUsage + $requestedAdditional > $limit) {
            throw new QuotaExceededException("Subscription usage limit reached: {$entitlementKey}");
        }
    }

    private function platformAdministrator(): bool
    {
        return (bool) (Auth::user()?->is_platform_admin ?? false);
    }
}
