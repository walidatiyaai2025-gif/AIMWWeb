<?php

namespace App\Jobs;

use App\Models\Execution;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Laravel adapter for canonical operation AIMW-AUTO-EEFDAB3DF8 (ExecutionJobStore).
 *
 * The canonical C# contract persists background execution lifecycle state. The
 * Laravel variant intentionally reuses the tenant-owned executions table rather
 * than introducing a second execution runtime.
 */
final class ExecutionJobStore
{
    public const OPERATION_ID = 'AIMW-AUTO-EEFDAB3DF8';

    public function __construct(private readonly ExecutionJobConfiguration $configuration) {}

    public function start(int $siteId, string $jobType): string
    {
        $this->configuration->validate($jobType, 'Running', 'Starting', null, 0, 1);

        // Site is tenant-scoped. A site from another tenant must fail closed.
        Site::query()->findOrFail($siteId);

        return DB::transaction(function () use ($siteId, $jobType): string {
            $jobId = (string) Str::uuid();

            Execution::query()->create([
                'operation_id' => $jobId,
                'request_id' => (string) Str::uuid(),
                'correlation_id' => (string) Str::uuid(),
                'site_id' => $siteId,
                'approval_id' => null,
                'actor_user_id' => null,
                'status' => 'Running',
                'job_type' => $jobType,
                'progress_percent' => 0,
                'current_step' => 'Starting',
                'failure' => null,
                'concurrency_token' => 1,
                'started_at' => now('UTC'),
            ]);

            return $jobId;
        });
    }

    public function report(string $jobId, int $percent, string $step): void
    {
        $progress = max(0, min(100, $percent));

        $this->transition($jobId, function (Execution $job) use ($progress, $step): void {
            $token = ((int) $job->concurrency_token) + 1;
            $this->configuration->validate(
                (string) $job->job_type,
                (string) $job->status,
                $step,
                $job->failure === null ? null : (string) $job->failure,
                $progress,
                $token,
            );

            $job->forceFill([
                'progress_percent' => $progress,
                'current_step' => $step,
                'concurrency_token' => $token,
            ])->save();
        });
    }

    public function complete(string $jobId): void
    {
        $this->transition($jobId, function (Execution $job): void {
            $token = ((int) $job->concurrency_token) + 1;
            $this->configuration->validate(
                (string) $job->job_type,
                'Completed',
                'Completed',
                $job->failure === null ? null : (string) $job->failure,
                100,
                $token,
            );

            $job->forceFill([
                'status' => 'Completed',
                'progress_percent' => 100,
                'current_step' => 'Completed',
                'completed_at' => now('UTC'),
                'concurrency_token' => $token,
            ])->save();
        });
    }

    public function fail(string $jobId, string $error): void
    {
        $this->transition($jobId, function (Execution $job) use ($error): void {
            $safeError = $this->redactErrorDetails($error);
            $token = ((int) $job->concurrency_token) + 1;
            $this->configuration->validate(
                (string) $job->job_type,
                'Failed',
                (string) $job->current_step,
                $safeError,
                (int) $job->progress_percent,
                $token,
            );

            $job->forceFill([
                'status' => 'Failed',
                'failure' => $safeError,
                'completed_at' => now('UTC'),
                'concurrency_token' => $token,
            ])->save();
        });
    }

    public function cancel(string $jobId): void
    {
        $this->transition($jobId, function (Execution $job): void {
            $token = ((int) $job->concurrency_token) + 1;
            $this->configuration->validate(
                (string) $job->job_type,
                'Canceled',
                'Canceled',
                $job->failure === null ? null : (string) $job->failure,
                (int) $job->progress_percent,
                $token,
            );

            $now = now('UTC');
            $job->forceFill([
                'status' => 'Canceled',
                'current_step' => 'Canceled',
                'completed_at' => $now,
                'cancelled_at' => $now,
                'concurrency_token' => $token,
            ])->save();
        });
    }

    /**
     * @return list<array{
     *     id:string,
     *     site_name:string,
     *     job_type:string,
     *     status:string,
     *     progress_percent:int,
     *     current_step:string,
     *     updated_at_utc:string,
     *     error_details:?string
     * }>
     */
    public function getRecent(int $take = 200): array
    {
        $take = max(1, min(1000, $take));

        return Execution::query()
            ->with('site')
            ->orderByDesc('updated_at')
            ->limit($take)
            ->get()
            ->map(static fn (Execution $job): array => [
                'id' => (string) $job->operation_id,
                'site_name' => (string) ($job->site?->name ?? ''),
                'job_type' => (string) $job->job_type,
                'status' => (string) $job->status,
                'progress_percent' => (int) $job->progress_percent,
                'current_step' => (string) $job->current_step,
                'updated_at_utc' => $job->updated_at?->copy()->utc()->toIso8601String() ?? '',
                'error_details' => $job->failure === null ? null : (string) $job->failure,
            ])
            ->all();
    }

    public function get(string $jobId): ?Execution
    {
        return Execution::query()->where('operation_id', $jobId)->first();
    }

    /**
     * Mutate one tenant-visible job under a row lock. Missing or cross-tenant
     * jobs deliberately behave like the canonical store's no-op missing lookup.
     *
     * @param  callable(Execution):void  $mutation
     */
    private function transition(string $jobId, callable $mutation): void
    {
        DB::transaction(function () use ($jobId, $mutation): void {
            $job = Execution::query()
                ->where('operation_id', $jobId)
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return;
            }

            $mutation($job);
        });
    }

    /**
     * ErrorDetails is persisted evidence, so obvious credential material is
     * stripped before storage while preserving non-secret diagnostic context.
     */
    private function redactErrorDetails(string $error): string
    {
        $error = preg_replace(
            '/(?i)(authorization\s*:\s*bearer\s+)[^\s,;]+/',
            '$1[REDACTED]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/(?i)(\bbearer\s+)[A-Za-z0-9._~+\/=\-]+/',
            '$1[REDACTED]',
            $error,
        ) ?? $error;
        $error = preg_replace(
            '/(?i)\b(password|passwd|secret|token|api[_-]?key|client_secret|access_token|refresh_token)\b\s*[:=]\s*([^\s,;&]+)/',
            '$1=[REDACTED]',
            $error,
        ) ?? $error;

        return $error;
    }
}
