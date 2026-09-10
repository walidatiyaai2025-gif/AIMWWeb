<?php

namespace App\Execution;

use App\Authorization\TenantAuthorizer;
use App\Operations\Redactor;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant-safe Laravel adaptation of canonical ExecutionCenterService.EnqueueExternal.
 *
 * External registration reuses the existing operation_executions / operation_logs control
 * plane. It never claims that the external runtime started, progressed, or completed work.
 */
final class ExecutionCenterExternalEnqueueService
{
    public const OPERATION_ID = 'AIMW-AUTO-0B1BC18769';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
        private readonly Redactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function enqueueExternal(
        int $ownerUserId,
        int $siteId,
        string $title,
        string $type,
        string $siteName,
        int $totalItems,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): array {
        if ($ownerUserId <= 0) {
            throw new InvalidArgumentException('A valid execution owner user ID is required.');
        }
        if ($siteId <= 0) {
            throw new InvalidArgumentException('A valid execution site ID is required.');
        }

        $title = trim($title);
        $type = trim($type);
        $siteName = trim($siteName);
        $idempotencyKey = trim($idempotencyKey);

        if ($title === '') {
            throw new InvalidArgumentException('Job title is required.');
        }
        if ($type === '') {
            throw new InvalidArgumentException('Job type is required.');
        }
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('External execution idempotency key is required.');
        }

        $this->authorizer->authorize('operations.manage');
        $tenantId = $this->context->id();
        $membership = $this->context->membership();
        if ((int) $membership->user_id !== $ownerUserId) {
            throw new AuthorizationException;
        }

        $siteExists = DB::table('sites')
            ->where('tenant_id', $tenantId)
            ->where('id', $siteId)
            ->exists();
        if (! $siteExists) {
            throw (new ModelNotFoundException)->setModel('site');
        }

        $totalItems = max(1, $totalItems);
        $fingerprint = $this->idempotencyFingerprint($idempotencyKey);
        $displayKey = $this->safeText($idempotencyKey) ?? '[REDACTED]';
        $safeTitle = $this->safeText($title) ?? '';
        $safeType = $this->safeText($type) ?? '';
        $safeSiteName = $this->safeText($siteName) ?? '';
        $normalizedCorrelationId = $this->normalizeOptional($correlationId);

        return DB::transaction(function () use (
            $tenantId,
            $ownerUserId,
            $siteId,
            $safeTitle,
            $safeType,
            $safeSiteName,
            $totalItems,
            $fingerprint,
            $displayKey,
            $normalizedCorrelationId,
        ): array {
            // Serialize external idempotency decisions per tenant on databases that support
            // row locks. SQLite ignores FOR UPDATE but still executes this inside one write
            // transaction; MySQL production uses the row lock to prevent duplicate races.
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first(['id']);

            $existing = DB::table('operation_executions')
                ->where('tenant_id', $tenantId)
                ->where('requested_by_user_id', $ownerUserId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->first(function (object $row) use ($fingerprint): bool {
                    $payload = $this->decodeMetadata($row->payload ?? null);

                    return ($payload['execution_mode'] ?? null) === 'External'
                        && hash_equals((string) ($payload['dedupe_fingerprint'] ?? ''), $fingerprint);
                });

            if ($existing !== null) {
                return $this->project($existing);
            }

            $correlation = $normalizedCorrelationId ?? (string) Str::uuid();
            $now = now();
            $payload = $this->redactor->redact([
                'title' => $safeTitle,
                'site_name' => $safeSiteName,
                'total_items' => $totalItems,
                'processed_items' => 0,
                'execution_mode' => 'External',
                'idempotency_display' => $displayKey,
                'dedupe_fingerprint' => $fingerprint,
            ]);

            $rowId = (int) DB::table('operation_executions')->insertGetId([
                'tenant_id' => $tenantId,
                'requested_by_user_id' => $ownerUserId,
                'type' => $safeType,
                'subject_type' => 'site',
                'subject_id' => (string) $siteId,
                'correlation_id' => $correlation,
                'status' => 'queued',
                'progress' => 0,
                'attempts' => 0,
                'max_attempts' => 1,
                'safe_to_cancel' => true,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'result' => null,
                'failure' => null,
                'started_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('operation_logs')->insert([
                'tenant_id' => $tenantId,
                'operation_execution_id' => $rowId,
                'correlation_id' => $correlation,
                'level' => 'info',
                'message' => $this->safeText("Registered external {$safeType} execution for {$safeSiteName}.")
                    ?? 'External execution registered.',
                'context' => json_encode($this->redactor->redact([
                    'source' => 'ExecutionCenterService.EnqueueExternal',
                    'operation_id' => self::OPERATION_ID,
                    'type' => $safeType,
                    'owner_user_id' => $ownerUserId,
                    'site_id' => $siteId,
                ]), JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
            ]);

            $created = DB::table('operation_executions')->where('id', $rowId)->first();
            if ($created === null) {
                throw new \LogicException('External execution registration could not be reloaded.');
            }

            return $this->project($created);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function project(object $row): array
    {
        $payload = $this->decodeMetadata($row->payload ?? null);

        return [
            'job_id' => (string) $row->correlation_id,
            'ledger' => 'operation_execution',
            'row_id' => (int) $row->id,
            'owner_user_id' => (int) $row->requested_by_user_id,
            'site_id' => (int) $row->subject_id,
            'title' => (string) ($payload['title'] ?? ''),
            'type' => (string) $row->type,
            'site_name' => (string) ($payload['site_name'] ?? ''),
            'status' => (string) $row->status,
            'progress' => (int) $row->progress,
            'total_items' => (int) ($payload['total_items'] ?? 1),
            'processed_items' => (int) ($payload['processed_items'] ?? 0),
            'execution_mode' => (string) ($payload['execution_mode'] ?? 'External'),
            'idempotency_key' => (string) ($payload['idempotency_display'] ?? '[REDACTED]'),
            'created_at' => (string) $row->created_at,
            'started_at' => $row->started_at === null ? null : (string) $row->started_at,
            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
            'error' => $this->safeText($row->failure ?? null),
        ];
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

    private function idempotencyFingerprint(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    private function normalizeOptional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
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
