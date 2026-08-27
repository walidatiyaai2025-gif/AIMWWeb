<?php

namespace App\Http\Middleware;

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

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
