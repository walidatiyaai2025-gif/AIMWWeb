<?php

namespace App\Email\Services;

use App\Jobs\RunEmailSchedulesJob;
use App\Models\EmailSchedule;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmailScheduleService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailDeliveryService $delivery,
        private readonly AuditLogger $audit,
    ) {}

    public function all(): array
    {
        return EmailSchedule::query()->orderBy('name')->get()->map(fn (EmailSchedule $schedule) => $this->serialize($schedule))->all();
    }

    public function save(?EmailSchedule $schedule, array $input): EmailSchedule
    {
        $recipient = trim((string) ($input['recipient'] ?? $schedule?->recipient));
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => 'Schedule recipient is invalid.']);
        }

        $schedule ??= new EmailSchedule;
        $schedule->fill(Arr::only($input, [
            'site_id', 'name', 'template_stable_id', 'locale', 'variables', 'enabled', 'interval_minutes', 'next_run_at',
        ]));
        $schedule->recipient = $recipient;
        $schedule->interval_minutes = min(max((int) ($schedule->interval_minutes ?: 1440), 15), 525600);
        $schedule->locale = $schedule->locale === 'ar' ? 'ar' : 'en';
        $schedule->save();

        $this->audit->record('email.schedule.changed', [
            'schedule_id' => $schedule->id,
            'enabled' => (bool) $schedule->enabled,
            'interval_minutes' => $schedule->interval_minutes,
        ], EmailSchedule::class, $schedule->id);

        return $schedule;
    }

    public function dispatchDue(): int
    {
        $count = 0;
        EmailSchedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('id')
            ->each(function (EmailSchedule $schedule) use (&$count): void {
                $dueAt = $schedule->next_run_at->copy();
                $this->delivery->queue([
                    'event_id' => (string) Str::uuid(),
                    'idempotency_key' => "schedule:{$schedule->id}:".$dueAt->getTimestamp(),
                    'recipient' => $schedule->recipient,
                    'template_stable_id' => $schedule->template_stable_id,
                    'locale' => $schedule->locale,
                    'variables' => $schedule->variables ?? [],
                ]);
                $schedule->update([
                    'last_run_at' => now(),
                    'next_run_at' => $dueAt->addMinutes($schedule->interval_minutes),
                ]);
                $count++;
            });

        return $count;
    }

    public function queueWorker(): void
    {
        RunEmailSchedulesJob::dispatch($this->context->id());
    }

    public function serialize(EmailSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'site_id' => $schedule->site_id,
            'name' => $schedule->name,
            'template_stable_id' => $schedule->template_stable_id,
            'recipient' => $this->mask($schedule->recipient),
            'locale' => $schedule->locale,
            'variables' => $schedule->variables ?? [],
            'enabled' => (bool) $schedule->enabled,
            'interval_minutes' => $schedule->interval_minutes,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
        ];
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain ? Str::substr($local, 0, 1).'***@'.$domain : '[redacted]';
    }
}
