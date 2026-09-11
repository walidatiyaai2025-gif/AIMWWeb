<?php

namespace App\Jobs;

use App\Tenancy\TenantCache;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Distributed, tenant-scoped Laravel adaptation of the canonical
 * JobCancellationRegistry background-job operation.
 *
 * Canonical operation: AIMW-AUTO-13392A9E67.
 *
 * The source registry stores a replaceable registration per job, lets callers
 * request cancellation idempotently, and removes a registration only when the
 * disposer still owns the current registration. Laravel workers may execute in
 * different processes, so this adapter preserves those semantics in the shared
 * cache/Redis control plane instead of process-local memory.
 */
final class JobCancellationRegistry
{
    public const OPERATION_ID = 'AIMW-AUTO-13392A9E67';

    private const KEY_PREFIX = 'job-cancellation-registry:';

    private const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(private readonly TenantCache $cache) {}

    /**
     * Register or replace the current job attempt and return its opaque
     * registration token. A new registration clears cancellation intent for the
     * new attempt, exactly as replacing the source CancellationTokenSource does.
     */
    public function register(string $jobId, ?string $registrationId = null, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $jobId = $this->normalizeJobId($jobId);
        $registrationId ??= (string) Str::uuid();
        if (! Str::isUuid($registrationId)) {
            throw new InvalidArgumentException('A valid registration UUID is required.');
        }
        if ($ttlSeconds < 1 || $ttlSeconds > self::DEFAULT_TTL_SECONDS) {
            throw new InvalidArgumentException('Registration TTL must be between 1 and 86400 seconds.');
        }

        $this->withLock($jobId, function () use ($jobId, $registrationId, $ttlSeconds): void {
            $this->cache->put($this->stateKey($jobId), [
                'registration_id' => $registrationId,
                'cancel_requested' => false,
            ], now()->addSeconds($ttlSeconds));
        });

        return $registrationId;
    }

    /**
     * Request cancellation of the current registration. Repeated requests are
     * successful and do not create a second state transition.
     */
    public function tryCancel(string $jobId): bool
    {
        $jobId = $this->normalizeJobId($jobId);

        return $this->withLock($jobId, function () use ($jobId): bool {
            $state = $this->state($jobId);
            if ($state === null) {
                return false;
            }

            if (! $state['cancel_requested']) {
                $state['cancel_requested'] = true;
                $this->cache->put($this->stateKey($jobId), $state, now()->addSeconds(self::DEFAULT_TTL_SECONDS));
            }

            return true;
        });
    }

    public function isRegistered(string $jobId): bool
    {
        return $this->state($this->normalizeJobId($jobId)) !== null;
    }

    /**
     * Worker-side cancellation probe. Supplying the registration ID prevents a
     * stale retry/attempt from observing cancellation intended for its replacement.
     */
    public function isCancellationRequested(string $jobId, string $registrationId): bool
    {
        $jobId = $this->normalizeJobId($jobId);
        if (! Str::isUuid($registrationId)) {
            return false;
        }

        $state = $this->state($jobId);

        return $state !== null
            && hash_equals($state['registration_id'], $registrationId)
            && $state['cancel_requested'];
    }

    /**
     * Dispose/unregister only the registration that owns the current slot.
     * A stale attempt cannot remove a newer retry's registration.
     */
    public function unregister(string $jobId, string $registrationId): bool
    {
        $jobId = $this->normalizeJobId($jobId);
        if (! Str::isUuid($registrationId)) {
            return false;
        }

        return $this->withLock($jobId, function () use ($jobId, $registrationId): bool {
            $state = $this->state($jobId);
            if ($state === null || ! hash_equals($state['registration_id'], $registrationId)) {
                return false;
            }

            return $this->cache->forget($this->stateKey($jobId));
        });
    }

    /** @return array{registration_id:string,cancel_requested:bool}|null */
    private function state(string $jobId): ?array
    {
        $state = $this->cache->get($this->stateKey($jobId));
        if (! is_array($state)) {
            return null;
        }

        $registrationId = $state['registration_id'] ?? null;
        if (! is_string($registrationId) || ! Str::isUuid($registrationId)) {
            return null;
        }

        return [
            'registration_id' => $registrationId,
            'cancel_requested' => (bool) ($state['cancel_requested'] ?? false),
        ];
    }

    private function stateKey(string $jobId): string
    {
        return self::KEY_PREFIX.$jobId;
    }

    private function lockKey(string $jobId): string
    {
        return $this->cache->key(self::KEY_PREFIX.$jobId.':lock');
    }

    private function normalizeJobId(string $jobId): string
    {
        $jobId = trim($jobId);
        if (! Str::isUuid($jobId)) {
            throw new InvalidArgumentException('A valid job UUID is required.');
        }

        return Str::lower($jobId);
    }

    /** @template T @param callable():T $callback @return T */
    private function withLock(string $jobId, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKey($jobId), 5);

        try {
            return $lock->block(3, $callback);
        } catch (LockTimeoutException $exception) {
            throw new \RuntimeException('Job cancellation registry lock acquisition timed out.', previous: $exception);
        }
    }
}
