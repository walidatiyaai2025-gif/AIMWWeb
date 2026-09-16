<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $slug = (string) $request->route('tenant');
        $membership = TenantMembership::withoutGlobalScopes()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->whereHas('tenant', fn ($query) => $query->where('slug', $slug))
            ->with('tenant')
            ->firstOrFail();

        $this->context->activate($membership->tenant, $membership);
        $request->attributes->set('tenant_id', (int) $membership->tenant->getKey());

        if ($request->query->has('site')) {
            $siteId = $request->integer('site');
            $ownsSite = $siteId > 0 && Site::query()
                ->withoutGlobalScopes()
                ->whereKey($siteId)
                ->where('tenant_id', $membership->tenant_id)
                ->exists();

            abort_unless($ownsSite, 404);
        }

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
