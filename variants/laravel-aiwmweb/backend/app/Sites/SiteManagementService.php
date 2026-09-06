<?php

namespace App\Sites;

use App\Models\Site;
use App\Tenancy\TenantContext;

final class SiteManagementService
{
    public const OPERATION_ID = 'AIMW-AI-95AC5F28A7';

    public function __construct(private readonly TenantContext $context)
    {
    }

    /**
     * Adaptation of canonical SiteManagementService.GetDetailsAsync.
     *
     * The Laravel connector never exposes stored connector secrets through this
     * read contract. WordPress username is intentionally empty because the
     * pairing protocol does not persist a separately readable username.
     *
     * @return array<string, mixed>|null
     */
    public function getDetails(int $siteId): ?array
    {
        if ($siteId < 1) {
            return null;
        }

        $site = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->context->id())
            ->whereKey($siteId)
            ->first([
                'id',
                'name',
                'url',
                'home_url',
                'wordpress_version',
                'language_code',
                'connection_status',
                'last_verified_at',
            ]);

        if ($site === null) {
            return null;
        }

        return [
            'id' => (int) $site->id,
            'name' => (string) $site->name,
            // Keep the existing Laravel API field while exposing the canonical semantic alias.
            'url' => (string) $site->url,
            'site_url' => (string) $site->url,
            'home_url' => $site->home_url,
            'wordpress_version' => $site->wordpress_version,
            'language_code' => $site->language_code,
            'connection_status' => (string) $site->connection_status,
            'last_connection_test_at_utc' => $site->last_verified_at?->toISOString(),
            'user_name' => '',
        ];
    }
}
