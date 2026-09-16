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

        if ($request->isMethod('GET')
            && $request->route()?->uri() === 'tenants/{tenant}/context'
            && $request->query->has('site')) {
            $siteId = filter_var(
                $request->query('site'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            abort_unless($siteId !== false, 404);

            $ownedSiteExists = Site::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $siteId)
                ->where('tenant_id', $this->context->id())
                ->exists();
            abort_unless($ownedSiteExists, 404);
        }

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
