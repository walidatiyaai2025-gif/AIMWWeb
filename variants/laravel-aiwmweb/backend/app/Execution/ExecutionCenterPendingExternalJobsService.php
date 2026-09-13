<?php

namespace App\Execution;

use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-safe Laravel adaptation of canonical ExecutionCenterService.GetPendingExternalJobs.
 *
 * This service only enumerates the active tenant's already-registered external execution
 * control-plane rows. It never starts, executes, mutates, or claims external provider work.
 */
final class ExecutionCenterPendingExternalJobsService
{
    public const OPERATION_ID = 'AIMW-AUTO-E587C12B37';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function getPendingExternalJobs(int $take = 20): array
    {
        $this->authorizer->authorize('operations.manage');
        $tenantId = $this->context->id();
        $limit = max(1, min(100, $take));
        $jobs = [];

        $rows = DB::table('operation_executions')
            ->where('tenant_id', $tenantId)
            ->where('status', 'queued')
            ->whereNotNull('requested_by_user_id')
            ->where('subject_type', 'site')
            ->whereNotNull('subject_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $payload = $this->decodeMetadata($row->payload ?? null);
            if (($payload['execution_mode'] ?? null) !== 'External') {
                continue;
            }

            $siteId = filter_var(
                (string) $row->subject_id,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if ($siteId === false) {
                continue;
            }

            $jobs[] = [
                'job_id' => (string) $row->correlation_id,
                'ledger' => 'operation_execution',
                'row_id' => (int) $row->id,
                'owner_user_id' => (int) $row->requested_by_user_id,
                'site_id' => (int) $siteId,
                'title' => $this->safeText((string) ($payload['title'] ?? '')) ?? '',
                'type' => $this->safeText((string) $row->type) ?? '',
                'site_name' => $this->safeText((string) ($payload['site_name'] ?? '')) ?? '',
                'status' => 'queued',
                'progress' => (int) $row->progress,
                'total_items' => max(1, (int) ($payload['total_items'] ?? 1)),
                'processed_items' => max(0, (int) ($payload['processed_items'] ?? 0)),
                'execution_mode' => 'External',
                'idempotency_key' => $this->safeText((string) ($payload['idempotency_display'] ?? '')),
                'created_at' => (string) $row->created_at,
                'started_at' => $row->started_at === null ? null : (string) $row->started_at,
                'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
                'error' => $this->safeText($row->failure === null ? null : (string) $row->failure),
            ];

            if (count($jobs) >= $limit) {
                break;
            }
        }

        return $jobs;
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function safeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)\b\s*[:=]\s*[^\s,;]+/',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;
    }
}
