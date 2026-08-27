<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

final class TenantJobMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->activate($tenant);

        try {
            $next($job);
        } finally {
            $context->forget();
        }
    }
}
