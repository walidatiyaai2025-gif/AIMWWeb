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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        return $this->cache->put($this->key($key), $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($this->key($key));
    }
}
