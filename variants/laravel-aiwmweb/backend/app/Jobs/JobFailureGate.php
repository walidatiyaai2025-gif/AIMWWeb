<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class JobFailureGate
{
    public function canStart(int $siteId, string $jobType, ?CarbonImmutable $now = null): JobGateDecision
    {
        $settings = $this->settings();
        if (!$settings['pause_after_failures']) {
            return JobGateDecision::allowed();
        }

        $recent = $this->recentTerminalRuns($siteId, $jobType, $settings['consecutive_failures_before_pause']);

        return self::decide($recent, $settings, $now ?? CarbonImmutable::now('UTC'), $jobType);
    }

    /**
     * @param Collection<int, object|array<string, mixed>> $recent
     * @param array{pause_after_failures:bool,consecutive_failures_before_pause:int,failure_pause_minutes:int,auto_resume_after_pause:bool} $settings
     */
    public static function decide(Collection $recent, array $settings, CarbonImmutable $now, string $jobType): JobGateDecision
    {
        if (!$settings['pause_after_failures']) {
            return JobGateDecision::allowed();
        }

        $threshold = $settings['consecutive_failures_before_pause'];
        if ($recent->count() < $threshold || $recent->contains(fn ($run): bool => data_get($run, 'status') !== 'failed')) {
            return JobGateDecision::allowed();
        }

        $lastFailureUtc = $recent
            ->map(fn ($run): CarbonImmutable => CarbonImmutable::parse(data_get($run, 'completed_at') ?? data_get($run, 'updated_at'), 'UTC'))
            ->max();
        $resumeAtUtc = $lastFailureUtc->addMinutes($settings['failure_pause_minutes']);

        if ($now->greaterThanOrEqualTo($resumeAtUtc) && $settings['auto_resume_after_pause']) {
            return JobGateDecision::allowed();
        }

        $remainingMinutes = max(0, (int) ceil($now->diffInSeconds($resumeAtUtc, false) / 60));

        return new JobGateDecision(
            false,
            $resumeAtUtc,
            sprintf(
                '%s is paused after %d consecutive failures. Try again in %d minute(s), at %s.',
                $jobType,
                $threshold,
                $remainingMinutes,
                $resumeAtUtc->toIso8601String(),
            ),
        );
    }

    /**
     * Reuses the existing durable runtime records for the AI suggestion job. Other
     * job families remain fail-open until their own canonical stores are migrated.
     *
     * @return Collection<int, object>
     */
    private function recentTerminalRuns(int $siteId, string $jobType, int $limit): Collection
    {
        if (class_basename($jobType) !== 'GenerateSuggestionJob') {
            return collect();
        }

        return DB::table('suggestions')
            ->where('site_id', $siteId)
            ->whereIn('status', ['ready', 'failed'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['status', 'updated_at as completed_at', 'updated_at']);
    }

    /** @return array{pause_after_failures:bool,consecutive_failures_before_pause:int,failure_pause_minutes:int,auto_resume_after_pause:bool} */
    private function settings(): array
    {
        return [
            'pause_after_failures' => (bool) config('job_reliability.pause_after_failures', true),
            'consecutive_failures_before_pause' => min(20, max(1, (int) config('job_reliability.consecutive_failures_before_pause', 3))),
            'failure_pause_minutes' => min(1440, max(1, (int) config('job_reliability.failure_pause_minutes', 15))),
            'auto_resume_after_pause' => (bool) config('job_reliability.auto_resume_after_pause', true),
        ];
    }
}
