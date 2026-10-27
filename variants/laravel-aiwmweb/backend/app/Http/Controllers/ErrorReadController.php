<?php

namespace App\Http\Controllers;

use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ErrorReadController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext): View
    {
        return view('platform.error', [
            'errorId' => (string) ($request->attributes->get('request_id') ?? 'N/A'),
            'correlationId' => (string) ($request->attributes->get('correlation_id') ?? 'N/A'),
            'errorTime' => now()->format('Y-m-d H:i:s'),
            'logsHref' => $this->resolveLogsHref($request, $tenantContext),
        ]);
    }

    private function resolveLogsHref(Request $request, TenantContext $tenantContext): ?string
    {
        $userId = $request->user()?->getAuthIdentifier();
        if ($userId === null || $tenantContext->active()) {
            return null;
        }

        $memberships = TenantMembership::withoutGlobalScopes()
            ->with('tenant')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        $eligibleTenantSlugs = [];

        foreach ($memberships as $membership) {
            $tenant = $membership->tenant;
            if ($tenant === null) {
                continue;
            }

            $tenantContext->activate($tenant, $membership);

            try {
                $allowed = $membership->hasPermission('operations.manage')
                    && $membership->hasPermission('diagnostics.view');
            } finally {
                $tenantContext->forget();
            }

            if (! $allowed) {
                continue;
            }

            $eligibleTenantSlugs[] = (string) $tenant->slug;
            if (count($eligibleTenantSlugs) > 1) {
                return null;
            }
        }

        if (count($eligibleTenantSlugs) !== 1) {
            return null;
        }

        return route('canonical.alias.logs', ['tenant' => $eligibleTenantSlugs[0]], false);
    }
}
