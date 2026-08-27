<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Cache\Repository;

final class TenantLock
{
    public function __construct(private readonly TenantCache $keys, private readonly Repository $cache) {}

    public function block(string $name, int $seconds, Closure $callback): mixed
    {
        return $this->cache->lock($this->keys->key("lock:{$name}"), $seconds)->block($seconds, $callback);
    }
}
