<?php

namespace Tests\Unit;

use App\Jobs\JobFailureGate;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class JobFailureGateTest extends TestCase
{
    private array $settings = [
        'pause_after_failures' => true,
        'consecutive_failures_before_pause' => 3,
        'failure_pause_minutes' => 15,
        'auto_resume_after_pause' => true,
    ];

    public function test_allows_when_pause_feature_is_disabled(): void
    {
        $settings = $this->settings;
        $settings['pause_after_failures'] = false;

        $decision = JobFailureGate::decide(collect(), $settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertTrue($decision->canRun);
    }

    public function test_allows_with_fewer_failures_than_threshold(): void
    {
        $recent = collect([
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:58:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:57:00'],
        ]);

        $decision = JobFailureGate::decide($recent, $this->settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertTrue($decision->canRun);
    }

    public function test_non_failure_breaks_consecutive_failure_sequence(): void
    {
        $recent = collect([
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:58:00'],
            ['status' => 'ready', 'updated_at' => '2026-08-30 19:57:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:56:00'],
        ]);

        $decision = JobFailureGate::decide($recent, $this->settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertTrue($decision->canRun);
    }

    public function test_pauses_after_threshold_and_returns_resume_metadata(): void
    {
        $recent = collect([
            ['status' => 'failed', 'completed_at' => '2026-08-30 19:58:00'],
            ['status' => 'failed', 'completed_at' => '2026-08-30 19:57:00'],
            ['status' => 'failed', 'completed_at' => '2026-08-30 19:56:00'],
        ]);

        $decision = JobFailureGate::decide($recent, $this->settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertFalse($decision->canRun);
        $this->assertSame('2026-08-30T20:13:00+00:00', $decision->resumeAtUtc?->toIso8601String());
        $this->assertStringContainsString('3 consecutive failures', (string) $decision->message);
        $this->assertStringContainsString('13 minute(s)', (string) $decision->message);
    }

    public function test_auto_resumes_after_pause_window(): void
    {
        $recent = collect([
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:40:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:39:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:38:00'],
        ]);

        $decision = JobFailureGate::decide($recent, $this->settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertTrue($decision->canRun);
    }

    public function test_manual_resume_mode_remains_paused_after_window(): void
    {
        $settings = $this->settings;
        $settings['auto_resume_after_pause'] = false;
        $recent = collect([
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:40:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:39:00'],
            ['status' => 'failed', 'updated_at' => '2026-08-30 19:38:00'],
        ]);

        $decision = JobFailureGate::decide($recent, $settings, CarbonImmutable::parse('2026-08-30 20:00:00', 'UTC'), 'GenerateSuggestionJob');

        $this->assertFalse($decision->canRun);
        $this->assertStringContainsString('0 minute(s)', (string) $decision->message);
    }
}
