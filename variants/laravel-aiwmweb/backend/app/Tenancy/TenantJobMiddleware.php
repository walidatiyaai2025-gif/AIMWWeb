<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TenantJobMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        $context = app(TenantContext::class);
        $previousTenant = $context->active() ? $context->tenant() : null;
        $previousMembership = null;
        if ($previousTenant !== null) {
            try {
                $previousMembership = $context->membership();
            } catch (Throwable) {
                $previousMembership = null;
            }
        }
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->activate($tenant);

        $logContext = [
            'job_id' => method_exists($job, 'runtimeJobId') ? $job->runtimeJobId() : null,
            'job_class' => $job::class,
            'tenant_id' => (int) $job->tenantId,
            'correlation_id' => property_exists($job, 'correlationId') ? $job->correlationId : null,
        ];

        Log::info('queue.job.started', $logContext);

        try {
            $next($job);
            Log::info('queue.job.completed', $logContext);
        } catch (Throwable $exception) {
            Log::error('queue.job.failed', $logContext + [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        } finally {
            if ($previousTenant !== null) {
                $context->activate($previousTenant, $previousMembership instanceof TenantMembership ? $previousMembership : null);
            } else {
                $context->forget();
            }
        }
    }
}
