<?php

namespace App\Operations;

use App\Billing\AccountEntitlementEnforcementService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use RuntimeException;

final class AutomationCenterJobSaveService
{
    private const AUTOMATION_LIMIT = 'automation.rules.max';
    private const PREMIUM_SEO = 'seo.audit.enabled';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountEntitlementEnforcementService $entitlements,
    ) {}

    public function create(array $validated, int $actorUserId, string $idempotencyKey): array
    {
        $tenantId = $this->context->id();
        $site = $this->ownedSite($tenantId, (int) $validated['site_id']);
        $desired = $this->normalize($validated, (string) $site->name);
        $requestHash = $this->requestHash($desired);

        $jobId = DB::transaction(function () use ($tenantId, $actorUserId, $idempotencyKey, $requestHash, $desired): int {
            $existing = DB::table('automation_center_jobs')
                ->where('tenant_id', $tenantId)
                ->where('owner_user_id', $actorUserId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Idempotency key was already used for different automation configuration.');
                }
                return (int) $existing->id;
            }

            if ($desired['type'] === 'SEO Audit') {
                $this->entitlements->requireBooleanCapabilityAsync(self::PREMIUM_SEO);
            }
            $currentUsage = DB::table('automation_center_jobs')
                ->where('tenant_id', $tenantId)
                ->where('owner_user_id', $actorUserId)
                ->count();
            $this->entitlements->requireAdditionalUsageAsync(self::AUTOMATION_LIMIT, $currentUsage, 1);

            $now = CarbonImmutable::now('UTC');
            $jobId = DB::table('automation_center_jobs')->insertGetId([
                'tenant_id' => $tenantId,
                'site_id' => $desired['site_id'],
                'owner_user_id' => $actorUserId,
                'name' => $desired['name'],
                'site_name' => $desired['site_name'],
                'type' => $desired['type'],
                'frequency' => $desired['frequency'],
                'interval_value' => $desired['interval_value'],
                'time_of_day' => $desired['time_of_day'],
                'enabled' => $desired['enabled'],
                'retry_count' => $desired['retry_count'],
                'last_status' => $desired['enabled'] ? 'Scheduled' : 'Disabled',
                'next_run_at' => $this->calculateNextRun($now, $desired),
                'version' => 1,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit($tenantId, $actorUserId, $jobId, 'created', 1, $desired, $now);

            return (int) $jobId;
        }, 3);

        return $this->authoritativeResult($tenantId, $actorUserId, $jobId, $desired, $requestHash);
    }

    public function update(int $jobId, array $validated, int $actorUserId): array
    {
        $tenantId = $this->context->id();
        $site = $this->ownedSite($tenantId, (int) $validated['site_id']);
        $desired = $this->normalize($validated, (string) $site->name);
        $expectedVersion = (int) $validated['expected_version'];

        DB::transaction(function () use ($tenantId, $actorUserId, $jobId, $expectedVersion, $desired): void {
            $current = $this->ownedJobQuery($tenantId, $actorUserId, $jobId)->lockForUpdate()->first();
            if ($current === null) {
                throw new NotFoundHttpException('Automation job was not found.');
            }

            if ($this->configurationMatches($current, $desired)) {
                return;
            }
            if ((int) $current->version !== $expectedVersion) {
                throw new ConflictHttpException('Automation job was changed by another request.');
            }
            if ($desired['type'] === 'SEO Audit') {
                $this->entitlements->requireBooleanCapabilityAsync(self::PREMIUM_SEO);
            }

            $now = CarbonImmutable::now('UTC');
            $nextVersion = $expectedVersion + 1;
            $updated = $this->ownedJobQuery($tenantId, $actorUserId, $jobId)
                ->where('version', $expectedVersion)
                ->update([
                    'site_id' => $desired['site_id'],
                    'name' => $desired['name'],
                    'site_name' => $desired['site_name'],
                    'type' => $desired['type'],
                    'frequency' => $desired['frequency'],
                    'interval_value' => $desired['interval_value'],
                    'time_of_day' => $desired['time_of_day'],
                    'enabled' => $desired['enabled'],
                    'retry_count' => $desired['retry_count'],
                    'last_status' => $desired['enabled'] ? 'Scheduled' : 'Disabled',
                    'next_run_at' => $this->calculateNextRun($now, $desired),
                    'version' => $nextVersion,
                    'idempotency_key' => null,
                    'request_hash' => null,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new ConflictHttpException('Automation job was changed by another request.');
            }
            $this->audit($tenantId, $actorUserId, $jobId, 'updated', $nextVersion, $desired, $now);
        }, 3);

        return $this->authoritativeResult($tenantId, $actorUserId, $jobId, $desired, null);
    }

    private function ownedSite(int $tenantId, int $siteId): object
    {
        $site = DB::table('sites')->where('tenant_id', $tenantId)->where('id', $siteId)->first(['id', 'name']);
        if ($site === null) {
            throw new NotFoundHttpException('Site was not found.');
        }
        return $site;
    }

    private function ownedJobQuery(int $tenantId, int $ownerUserId, int $jobId): Builder
    {
        return DB::table('automation_center_jobs')
            ->where('tenant_id', $tenantId)
            ->where('owner_user_id', $ownerUserId)
            ->where('id', $jobId);
    }

    private function normalize(array $validated, string $siteName): array
    {
        return [
            'name' => trim((string) $validated['name']),
            'site_id' => (int) $validated['site_id'],
            'site_name' => $siteName,
            'type' => (string) $validated['type'],
            'frequency' => strtolower((string) $validated['frequency']),
            'interval_value' => (int) $validated['interval_value'],
            'time_of_day' => (string) $validated['time_of_day'],
            'enabled' => (bool) $validated['enabled'],
            'retry_count' => (int) $validated['retry_count'],
        ];
    }

    private function requestHash(array $desired): string
    {
        return hash('sha256', json_encode($desired, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function configurationMatches(object $row, array $desired): bool
    {
        return (string) $row->name === $desired['name']
            && (int) $row->site_id === $desired['site_id']
            && (string) $row->site_name === $desired['site_name']
            && (string) $row->type === $desired['type']
            && (string) $row->frequency === $desired['frequency']
            && (int) $row->interval_value === $desired['interval_value']
            && (string) $row->time_of_day === $desired['time_of_day']
            && (bool) $row->enabled === $desired['enabled']
            && (int) $row->retry_count === $desired['retry_count'];
    }

    private function authoritativeResult(int $tenantId, int $actorUserId, int $jobId, array $desired, ?string $requestHash): array
    {
        $row = $this->ownedJobQuery($tenantId, $actorUserId, $jobId)->first();
        if ($row === null || ! $this->configurationMatches($row, $desired)) {
            throw new RuntimeException('Automation save could not be verified from authoritative persistence.');
        }
        if ($requestHash !== null && ! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new RuntimeException('Automation idempotency state could not be verified from authoritative persistence.');
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'site_id' => (int) $row->site_id,
            'site_name' => (string) $row->site_name,
            'type' => (string) $row->type,
            'frequency' => (string) $row->frequency,
            'interval_value' => (int) $row->interval_value,
            'time_of_day' => (string) $row->time_of_day,
            'enabled' => (bool) $row->enabled,
            'retry_count' => (int) $row->retry_count,
            'last_status' => (string) $row->last_status,
            'next_run_at' => (string) $row->next_run_at,
            'version' => (int) $row->version,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    private function calculateNextRun(CarbonImmutable $from, array $desired): CarbonImmutable
    {
        $interval = max(1, $desired['interval_value']);
        if ($desired['frequency'] === 'hourly') {
            return $from->addHours($interval);
        }

        [$hour, $minute] = array_map('intval', explode(':', $desired['time_of_day']));
        $candidate = $from->setTime($hour, $minute, 0);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate = match ($desired['frequency']) {
                'weekly' => $candidate->addDays(7 * $interval),
                'monthly' => $candidate->addMonthsNoOverflow($interval),
                default => $candidate->addDays($interval),
            };
        }
        return $candidate;
    }

    private function audit(int $tenantId, int $actorUserId, int $jobId, string $action, int $version, array $desired, CarbonImmutable $now): void
    {
        DB::table('automation_center_job_audits')->insert([
            'tenant_id' => $tenantId,
            'automation_center_job_id' => $jobId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'version' => $version,
            'configuration' => json_encode($desired, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
        ]);
    }
}
