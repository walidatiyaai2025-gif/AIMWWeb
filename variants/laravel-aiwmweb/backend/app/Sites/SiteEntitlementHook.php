<?php

namespace App\Sites;

use App\Models\Site;
use RuntimeException;

final class SiteEntitlementHook
{
    public function snapshot(): array
    {
        $contract = 'App\\Billing\\EntitlementService';
        if (! class_exists($contract)) {
            return [
                'state' => 'TEMPORARILY_UNAVAILABLE',
                'source' => 'PR #266 EntitlementService not integrated',
                'site_limit' => null,
                'site_count' => Site::query()->count(),
            ];
        }

        $service = app($contract);
        $limit = $service->limit('sites') ?? $service->limit('site_count');

        return [
            'state' => 'SUPPORTED_ENABLED',
            'source' => $contract,
            'site_limit' => $limit,
            'site_count' => Site::query()->count(),
            'billing' => $service->snapshot(),
        ];
    }

    public function assertCanCreate(): void
    {
        $snapshot = $this->snapshot();
        if ($snapshot['state'] !== 'SUPPORTED_ENABLED' || $snapshot['site_limit'] === null) {
            return;
        }
        if ($snapshot['site_count'] >= $snapshot['site_limit']) {
            throw new RuntimeException('Site entitlement limit reached.');
        }
    }
}
