<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Cache\Repository;

final class TenantCache
{
    public function __construct(private readonly TenantContext $context, private readonly Repository $cache) {}

    public function key(string $key): string
    {
        return "tenant:{$this->context->id()}:{$key}";
    }

    public function remember(string $key, mixed $ttl, Closure $callback): mixed
    {
        return $this->cache->remember($this->key($key), $ttl, $callback);
    }
}
