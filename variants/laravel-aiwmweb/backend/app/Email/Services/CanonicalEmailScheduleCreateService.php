<?php

namespace App\Email\Services;

use App\Models\EmailSchedule;
use App\Models\Site;
use App\Models\SiteEmailRecipient;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CanonicalEmailScheduleCreateService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailScheduleService $schedules,
    ) {}

    public function create(array $input, string $actorEmail, string $idempotencyKey): array
    {
        $tenantId = $this->context->id();
        $siteId = ($input['scope'] ?? 'Account') === 'Site' ? (int) ($input['site_id'] ?? 0) : null;
        $site = $siteId ? Site::query()->findOrFail($siteId) : null;
        $recipient = $this->recipient($siteId, $actorEmail);
        $desired = $this->desired($input, $siteId, $site?->name, $recipient);
        $name = 'canonical-email-schedule:'.hash('sha256', $idempotencyKey);

        $schedule = DB::transaction(function () use ($tenantId, $name, $desired): EmailSchedule {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $existing = EmailSchedule::query()->where('name', $name)->first();
            if ($existing) {
                if (! $this->matches($existing, $desired)) {
                    throw new ConflictHttpException('Idempotency key was already used for a different email schedule.');
                }
                return $existing;
            }

            return $this->schedules->save(null, [
                ...$desired,
                'name' => $name,
            ]);
        }, 3);

        $fresh = EmailSchedule::query()->findOrFail($schedule->id);
        if (! $this->matches($fresh, $desired)) {
            throw new \RuntimeException('Email schedule create could not be verified from authoritative persistence.');
        }

        return $this->serialize($fresh, $site?->name);
    }

    private function desired(array $input, ?int $siteId, ?string $siteName, string $recipient): array
    {
        $scope = $siteId ? 'Site' : 'Account';
        $frequency = ucfirst(strtolower((string) $input['frequency']));
        $timezone = (string) $input['timezone_id'];
        $time = (string) $input['time_of_day'];
        $weekday = isset($input['weekday']) ? (int) $input['weekday'] : null;
        $monthDay = isset($input['month_day']) ? (int) $input['month_day'] : null;
        $culture = strtolower((string) ($input['culture'] ?? 'en')) === 'ar' ? 'ar' : 'en';

        return [
            'site_id' => $siteId,
            'template_stable_id' => 'operation.alert',
            'recipient' => $recipient,
            'locale' => $culture,
            'variables' => [
                'title' => $scope === 'Account' ? 'Dashboard digest' : 'Site operational report',
                'message' => $scope === 'Account'
                    ? 'Scheduled dashboard digest.'
                    : 'Scheduled operational report for '.($siteName ?: 'site').'.',
            ],
            'enabled' => (bool) ($input['enabled'] ?? true),
            'frequency' => $frequency,
            'timezone_id' => $timezone,
            'time_of_day' => $time,
            'weekday' => $weekday,
            'month_day' => $monthDay,
            'retry_count' => (int) ($input['retry_count'] ?? 3),
            'retry_delay_minutes' => (int) ($input['retry_delay_minutes'] ?? 5),
            'interval_minutes' => match ($frequency) {
                'Hourly' => 60,
                'Weekly' => 10080,
                'Monthly' => 43200,
                default => 1440,
            },
            'next_run_at' => $this->nextRun($frequency, $timezone, $time, $weekday, $monthDay),
        ];
    }

    private function nextRun(string $frequency, string $timezone, string $time, ?int $weekday, ?int $monthDay): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $now = CarbonImmutable::now($zone);
        if ($frequency === 'Hourly') {
            return $now->addHour()->startOfHour()->setTimezone('UTC');
        }

        $candidate = $now->setTime($hour, $minute, 0);
        if ($frequency === 'Weekly') {
            $target = $weekday ?? 1;
            $days = ($target - (int) $candidate->dayOfWeek + 7) % 7;
            $candidate = $candidate->addDays($days);
            if ($candidate->lessThanOrEqualTo($now)) $candidate = $candidate->addWeek();
        } elseif ($frequency === 'Monthly') {
            $day = min($monthDay ?? 1, $candidate->daysInMonth);
            $candidate = $candidate->day($day);
            if ($candidate->lessThanOrEqualTo($now)) {
                $candidate = $candidate->addMonthNoOverflow();
                $candidate = $candidate->day(min($monthDay ?? 1, $candidate->daysInMonth));
            }
        } elseif ($candidate->lessThanOrEqualTo($now)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->setTimezone('UTC');
    }

    private function recipient(?int $siteId, string $actorEmail): string
    {
        if ($siteId) {
            $configured = SiteEmailRecipient::query()
                ->where('site_id', $siteId)
                ->where('is_enabled', true)
                ->orderBy('id')
                ->value('email_address');
            if (is_string($configured) && filter_var($configured, FILTER_VALIDATE_EMAIL)) return $configured;
        }
        if (! filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => 'The authenticated user has no valid delivery email address.']);
        }
        return $actorEmail;
    }

    private function matches(EmailSchedule $schedule, array $desired): bool
    {
        return ($schedule->site_id === null ? null : (int) $schedule->site_id) === $desired['site_id']
            && (string) $schedule->template_stable_id === $desired['template_stable_id']
            && (string) $schedule->recipient === $desired['recipient']
            && (string) $schedule->locale === $desired['locale']
            && (bool) $schedule->enabled === $desired['enabled']
            && (string) $schedule->frequency === $desired['frequency']
            && (string) $schedule->timezone_id === $desired['timezone_id']
            && (string) $schedule->time_of_day === $desired['time_of_day']
            && ($schedule->weekday === null ? null : (int) $schedule->weekday) === $desired['weekday']
            && ($schedule->month_day === null ? null : (int) $schedule->month_day) === $desired['month_day']
            && (int) $schedule->retry_count === $desired['retry_count']
            && (int) $schedule->retry_delay_minutes === $desired['retry_delay_minutes']
            && (int) $schedule->interval_minutes === $desired['interval_minutes']
            && ($schedule->variables ?? []) == $desired['variables'];
    }

    private function serialize(EmailSchedule $schedule, ?string $siteName): array
    {
        return [
            'id' => (int) $schedule->id,
            'scope' => $schedule->site_id ? 'Site' : 'Account',
            'site_id' => $schedule->site_id ? (int) $schedule->site_id : null,
            'site_name' => $siteName,
            'locale' => (string) $schedule->locale,
            'enabled' => (bool) $schedule->enabled,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'frequency' => (string) $schedule->frequency,
            'timezone_id' => (string) $schedule->timezone_id,
            'time_of_day' => (string) $schedule->time_of_day,
            'weekday' => $schedule->weekday === null ? null : (int) $schedule->weekday,
            'month_day' => $schedule->month_day === null ? null : (int) $schedule->month_day,
            'retry_count' => (int) $schedule->retry_count,
            'retry_delay_minutes' => (int) $schedule->retry_delay_minutes,
        ];
    }
}
