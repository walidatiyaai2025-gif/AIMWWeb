<?php

namespace App\Email\Services;

use App\Authorization\TenantAuthorizer;
use App\Billing\EntitlementService;
use App\Models\Site;
use App\Models\SiteEmailRecipient;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SiteEmailRecipientService
{
    public const ADD_OPERATION_ID = 'AIMW-BILL-07134347F9';

    public const ENTITLEMENT_KEY = 'email.siteRecipients.max';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
    ) {}

    /** @return Collection<int, SiteEmailRecipient> */
    public function listAsync(int $siteId): Collection
    {
        $this->authorizer->authorize('settings.manage');
        Site::query()->findOrFail($siteId);

        return SiteEmailRecipient::query()
            ->where('site_id', $siteId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function addAsync(int $siteId, string $emailAddress, ?string $displayName): SiteEmailRecipient
    {
        $this->authorizer->authorize('settings.manage');
        $email = $this->normalizeEmail($emailAddress);
        $normalized = mb_strtoupper($email, 'UTF-8');
        $display = $this->normalizeDisplayName($displayName);
        $userId = (int) $this->context->membership()->user_id;

        return DB::transaction(function () use ($siteId, $email, $normalized, $display, $userId): SiteEmailRecipient {
            Site::query()->whereKey($siteId)->lockForUpdate()->firstOrFail();

            if (SiteEmailRecipient::query()
                ->where('site_id', $siteId)
                ->where('normalized_email_address', $normalized)
                ->exists()) {
                throw ValidationException::withMessages([
                    'email_address' => 'This email address is already configured for the selected site.',
                ]);
            }

            $this->assertAdditionalRecipientAllowed($siteId, $userId);

            return SiteEmailRecipient::query()->create([
                'site_id' => $siteId,
                'email_address' => $email,
                'normalized_email_address' => $normalized,
                'display_name' => $display,
                'is_enabled' => true,
                'created_by_user_id' => $userId > 0 ? $userId : null,
            ]);
        }, 3);
    }

    private function assertAdditionalRecipientAllowed(int $siteId, int $userId): void
    {
        $platformAdmin = $userId > 0
            && (bool) User::query()->whereKey($userId)->value('platform_admin');
        if ($platformAdmin) {
            return;
        }

        $plan = $this->entitlements->plan();
        if ($plan === null) {
            throw ValidationException::withMessages([
                'email_address' => 'An active subscription is required to add a site email recipient.',
            ]);
        }

        $limits = $plan->limits ?? [];
        if (! array_key_exists(self::ENTITLEMENT_KEY, $limits)) {
            throw ValidationException::withMessages([
                'email_address' => "The current plan does not configure the '".self::ENTITLEMENT_KEY."' limit.",
            ]);
        }

        $rawLimit = $limits[self::ENTITLEMENT_KEY];
        if ($rawLimit === null) {
            return;
        }
        if (! is_numeric($rawLimit) || (int) $rawLimit < 0) {
            throw ValidationException::withMessages([
                'email_address' => "The current plan has an invalid '".self::ENTITLEMENT_KEY."' limit.",
            ]);
        }

        $currentUsage = SiteEmailRecipient::query()->where('site_id', $siteId)->count();
        $limit = (int) $rawLimit;
        if ($currentUsage + 1 > $limit) {
            throw ValidationException::withMessages([
                'email_address' => "The current plan recipient limit of {$limit} has been reached.",
            ]);
        }
    }

    private function normalizeEmail(string $emailAddress): string
    {
        $clean = trim($emailAddress);
        if ($clean === '') {
            throw ValidationException::withMessages(['email_address' => 'Email address is required.']);
        }
        if (mb_strlen($clean) > 320) {
            throw ValidationException::withMessages(['email_address' => 'Email address is too long.']);
        }
        if (filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email_address' => 'Enter a valid email address.']);
        }

        return $clean;
    }

    private function normalizeDisplayName(?string $displayName): ?string
    {
        $clean = trim((string) $displayName);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > 120) {
            throw ValidationException::withMessages(['display_name' => 'Display name is too long.']);
        }

        return $clean;
    }
}
