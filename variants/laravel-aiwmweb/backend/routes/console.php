<?php

use App\Jobs\RuntimeQueueSmokeJob;
use App\Models\Tenant;
use App\Services\RuntimeHealthService;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use RuntimeException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('runtime:scheduler-heartbeat', function () {
    Cache::put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time(), now()->addMinutes(10));
    $this->info('SCHEDULER_HEARTBEAT=PASS');
})->purpose('Refresh the scheduler freshness evidence used by readiness checks');

Schedule::call(function (): void {
    Cache::put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time(), now()->addMinutes(10));
})
    ->name('runtime.scheduler.heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

Artisan::command('runtime:health {--ready}', function () {
    $report = $this->option('ready')
        ? app(RuntimeHealthService::class)->ready()
        : app(RuntimeHealthService::class)->live();

    $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return ($report['status'] ?? null) === 'not_ready' ? self::FAILURE : self::SUCCESS;
})->purpose('Probe Laravel AIWMWeb runtime health from a non-interactive shell');

Artisan::command('runtime:mysql-smoke', function () {
    if (DB::getDriverName() !== 'mysql') {
        throw new RuntimeException('runtime:mysql-smoke requires MySQL/MariaDB-compatible production acceptance.');
    }

    $database = DB::getDatabaseName();
    $foreignKeys = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', $database)
        ->where('TABLE_NAME', 'tenant_memberships')
        ->count();

    if ($foreignKeys < 2) {
        throw new RuntimeException('Tenant membership foreign keys are missing.');
    }

    $membershipIndexes = collect(DB::select('SHOW INDEX FROM tenant_memberships'))
        ->groupBy('Key_name')
        ->map(fn ($rows) => [
            'unique' => ((int) $rows->first()->Non_unique) === 0,
            'columns' => $rows->sortBy('Seq_in_index')->pluck('Column_name')->values()->all(),
        ]);

    $hasTenantUserUnique = $membershipIndexes->contains(
        fn (array $index): bool => $index['unique'] && $index['columns'] === ['tenant_id', 'user_id'],
    );

    if (! $hasTenantUserUnique) {
        throw new RuntimeException('Tenant membership composite unique constraint is missing.');
    }

    $roleIndexes = collect(DB::select('SHOW INDEX FROM roles'))
        ->groupBy('Key_name')
        ->map(fn ($rows) => [
            'unique' => ((int) $rows->first()->Non_unique) === 0,
            'columns' => $rows->sortBy('Seq_in_index')->pluck('Column_name')->values()->all(),
        ]);

    if (! $roleIndexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['tenant_id', 'name'])) {
        throw new RuntimeException('Tenant role composite unique constraint is missing.');
    }

    $slug = 'runtime-tx-'.Str::lower(Str::random(12));
    try {
        DB::transaction(function () use ($slug): void {
            Tenant::query()->create(['name' => 'Runtime transaction smoke', 'slug' => $slug]);
            throw new RuntimeException('intentional rollback');
        });
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'intentional rollback') {
            throw $exception;
        }
    }

    if (Tenant::query()->where('slug', $slug)->exists()) {
        throw new RuntimeException('Transaction rollback validation failed.');
    }

    $this->info('MYSQL_RUNTIME_ACCEPTANCE=PASS');
})->purpose('Validate production MySQL foreign keys, tenant uniques, indexes and rollback semantics');

Artisan::command('runtime:redis-smoke', function () {
    if (config('cache.default') !== 'redis' || config('queue.default') !== 'redis') {
        throw new RuntimeException('Redis smoke requires Redis cache and queue configuration.');
    }

    $tenantA = Tenant::query()->create(['name' => 'Runtime Redis A', 'slug' => 'runtime-redis-a-'.Str::lower(Str::random(8))]);
    $tenantB = Tenant::query()->create(['name' => 'Runtime Redis B', 'slug' => 'runtime-redis-b-'.Str::lower(Str::random(8))]);
    $context = app(TenantContext::class);
    $keys = app(TenantCache::class);
    $lockA = null;
    $lockB = null;

    try {
        $context->activate($tenantA);
        $keyA = $keys->key('lock:runtime-smoke');
        $lockA = Cache::lock($keyA, 10);
        if (! $lockA->get()) {
            throw new RuntimeException('Unable to acquire Tenant A Redis lock.');
        }
        $context->forget();

        $context->activate($tenantB);
        $keyB = $keys->key('lock:runtime-smoke');
        $lockB = Cache::lock($keyB, 10);
        if (! $lockB->get()) {
            throw new RuntimeException('Unable to acquire Tenant B Redis lock.');
        }

        if ($keyA === $keyB) {
            throw new RuntimeException('Tenant Redis lock namespaces collided.');
        }

        $this->info('REDIS_TENANT_LOCK_ISOLATION=PASS');
    } finally {
        optional($lockB)->release();
        optional($lockA)->release();
        $context->forget();
        $tenantA->delete();
        $tenantB->delete();
    }
})->purpose('Validate real Redis tenant lock partitioning');

Artisan::command('runtime:queue-smoke {--timeout=30}', function () {
    if (config('queue.default') !== 'redis' || config('cache.default') !== 'redis') {
        throw new RuntimeException('Queue smoke requires Redis queue and cache configuration.');
    }

    $token = (string) Str::uuid();
    $tenantA = Tenant::query()->create(['name' => 'Runtime Queue A', 'slug' => 'runtime-queue-a-'.Str::lower(Str::random(8))]);
    $tenantB = Tenant::query()->create(['name' => 'Runtime Queue B', 'slug' => 'runtime-queue-b-'.Str::lower(Str::random(8))]);
    $keyA = "tenant:{$tenantA->id}:runtime-smoke:{$token}";
    $keyB = "tenant:{$tenantB->id}:runtime-smoke:{$token}";

    try {
        RuntimeQueueSmokeJob::dispatch($tenantA->id, $token);
        RuntimeQueueSmokeJob::dispatch($tenantB->id, $token);

        $deadline = microtime(true) + max(5, (int) $this->option('timeout'));
        do {
            $a = Cache::get($keyA);
            $b = Cache::get($keyB);
            if (is_array($a) && is_array($b)) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        if (! is_array($a ?? null) || ! is_array($b ?? null)) {
            throw new RuntimeException('Redis queue smoke timed out.');
        }

        if (($a['tenant_id'] ?? null) !== $tenantA->id || ($a['context_id'] ?? null) !== $tenantA->id) {
            throw new RuntimeException('Tenant A queue context was not restored correctly.');
        }

        if (($b['tenant_id'] ?? null) !== $tenantB->id || ($b['context_id'] ?? null) !== $tenantB->id) {
            throw new RuntimeException('Tenant B queue context was not restored correctly.');
        }

        if (Cache::has("runtime-smoke:{$token}")) {
            throw new RuntimeException('Queue smoke leaked an unscoped cache key.');
        }

        $this->info('REDIS_QUEUE_TENANT_ISOLATION=PASS');
    } finally {
        Cache::forget($keyA);
        Cache::forget($keyB);
        $tenantA->delete();
        $tenantB->delete();
    }
})->purpose('Dispatch two tenant-aware jobs through a real Redis worker and verify namespace isolation');
