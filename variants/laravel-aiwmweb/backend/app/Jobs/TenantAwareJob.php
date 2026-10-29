<?php

namespace App\Jobs;

use App\Tenancy\TenantJobMiddleware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

abstract class TenantAwareJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    use SerializesModels {
        __unserialize as private restoreQueueState;
    }

    public function __unserialize(array $values): void
    {
        // PHP 8.3 requires inherited readonly provenance to be restored in the
        // declaring scope rather than via a child-scoped ReflectionProperty.
        foreach (['tenantId', 'correlationId'] as $name) {
            if (array_key_exists($name, $values)) {
                $this->{$name} = $values[$name];
                unset($values[$name]);
            }
        }
        $this->restoreQueueState($values);
    }

    public readonly ?string $correlationId;

    public function __construct(public readonly int $tenantId, ?string $correlationId = null)
    {
        if ($correlationId === null && app()->bound('request')) {
            $candidate = request()->attributes->get('correlation_id');
            $correlationId = is_string($candidate) ? $candidate : null;
        }

        $this->correlationId = $correlationId;
    }

    public function middleware(): array
    {
        return [new TenantJobMiddleware];
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:".static::class;
    }

    public function runtimeJobId(): ?string
    {
        $id = $this->job?->getJobId();

        return $id === null ? null : (string) $id;
    }
}
