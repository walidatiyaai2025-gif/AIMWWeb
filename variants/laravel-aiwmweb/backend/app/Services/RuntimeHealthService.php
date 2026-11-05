<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class RuntimeHealthService
{
    public const SCHEDULER_HEARTBEAT_KEY = 'runtime:scheduler:last_tick';

    /** @return array{status:string,service:string} */
    public function live(): array
    {
        return [
            'status' => 'live',
            'service' => 'laravel-aiwmweb',
        ];
    }

    /** @return array{status:string,checks:array<string,array{status:string}>} */
    public function ready(): array
    {
        if (config('cpanel.enabled')) {
            return $this->cpanelReady();
        }

        $checks = [
            'app' => ['status' => 'ok'],
            'database' => $this->probe('database', function (): void {
                DB::select('SELECT 1');
            }),
            'redis' => $this->probe('redis', function (): void {
                $result = Redis::connection()->command('ping');
                if ($result === false) {
                    throw new RuntimeException('Redis PING failed.');
                }
            }),
            'storage' => $this->probe('storage', function (): void {
                if (! is_writable(storage_path()) || ! is_writable(base_path('bootstrap/cache'))) {
                    throw new RuntimeException('Writable runtime paths are unavailable.');
                }
            }),
            'queue' => $this->probe('queue', function (): void {
                if (config('queue.default') !== 'redis') {
                    throw new RuntimeException('Production queue connection is not Redis.');
                }

                Queue::connection('redis')->size((string) env('REDIS_QUEUE', 'default'));
            }),
            'scheduler' => $this->probe('scheduler', function (): void {
                $tick = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
                if (! is_numeric($tick)) {
                    throw new RuntimeException('Scheduler heartbeat is missing.');
                }

                $maxAge = max(60, (int) env('SCHEDULER_HEALTH_MAX_AGE', 180));
                if ((time() - (int) $tick) > $maxAge) {
                    throw new RuntimeException('Scheduler heartbeat is stale.');
                }
            }),
        ];

        $ready = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return [
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ];
    }

    private function cpanelReady(): array
    {
        $checks = [
            'database' => $this->probe('database', fn () => DB::select('SELECT 1')),
            'storage' => $this->probe('storage', function (): void {
                if (! is_writable(storage_path()) || ! is_writable(base_path('bootstrap/cache'))) {
                    throw new RuntimeException('Runtime storage is not writable.');
                }
            }),
            'cache' => $this->probe('cache', function (): void {
                if (config('cache.default') !== 'database') {
                    throw new RuntimeException('cPanel cache must use the validated database driver.');
                }
                $key = 'runtime:cpanel:'.bin2hex(random_bytes(12));
                try {
                    Cache::store('database')->put($key, 'probe', 30);
                    if (Cache::store('database')->get($key) !== 'probe') {
                        throw new RuntimeException('Database cache round trip failed.');
                    }
                } finally {
                    Cache::store('database')->forget($key);
                }
            }),
            'sessions' => $this->probe('sessions', function (): void {
                if (config('session.driver') !== 'database') {
                    throw new RuntimeException('cPanel sessions must use the validated database driver.');
                }
                $handler = clone app('session')->driver('database')->getHandler();
                $handler->setExists(false);
                $id = 'runtime-cpanel-'.bin2hex(random_bytes(12));
                try {
                    if (! $handler->write($id, 'probe') || $handler->read($id) !== 'probe') {
                        throw new RuntimeException('Database session round trip failed.');
                    }
                } finally {
                    $handler->destroy($id);
                }
            }),
            'queue' => $this->probe('queue', function (): void {
                if (config('queue.default') !== 'database') {
                    throw new RuntimeException('cPanel queue must use the bounded database driver.');
                }
                Queue::connection('database')->size();
            }),
            'queue_processing' => ['status' => config('cpanel.cron_enabled') ? 'ok' : 'disabled'],
            'redis' => ['status' => 'not_required'],
            'ai' => ['status' => config('cpanel.ai_enabled') ? (config('cpanel.preflight_passed') && config('cpanel.cron_enabled') ? 'ok' : 'unavailable') : 'disabled'],
            'scheduler' => ! config('cpanel.cron_enabled') ? ['status' => 'disabled'] : $this->probe('scheduler', function (): void {
                $tick = Cache::store('database')->get(self::SCHEDULER_HEARTBEAT_KEY);
                if (! config('cpanel.preflight_passed') || ! is_numeric($tick) || time() - (int) $tick > 180) {
                    throw new RuntimeException('Validated cPanel scheduler heartbeat is missing or stale.');
                }
            }),
        ];

        return [
            'status' => collect($checks)->every(fn ($check) => in_array($check['status'], ['ok', 'disabled', 'not_required'], true)) ? 'ready' : 'not_ready',
            'profile' => 'cpanel',
            'hosting_preflight' => config('cpanel.preflight_passed') ? 'operator_validated' : 'pending',
            'checks' => $checks,
        ];
    }

    /** @return array{status:string} */
    private function probe(string $name, callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (Throwable $exception) {
            Log::warning('runtime.health.failed', [
                'check' => $name,
                'exception_class' => $exception::class,
            ]);

            return ['status' => 'unavailable'];
        }
    }
}
