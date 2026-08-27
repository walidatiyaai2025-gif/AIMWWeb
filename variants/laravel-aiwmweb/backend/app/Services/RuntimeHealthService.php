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
