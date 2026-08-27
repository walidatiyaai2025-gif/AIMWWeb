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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $tenantId) {}

    public function middleware(): array
    {
        return [new TenantJobMiddleware];
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:".static::class;
    }
}
