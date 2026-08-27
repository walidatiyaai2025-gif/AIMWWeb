<?php

namespace App\Operations;

use App\Jobs\GenerateReportExport;
use App\Models\AuditEvent;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class OperationsControlPlaneService
{
    private const TASK_TYPES = ['sync', 'backup_l1', 'backup_l2', 'backup_l3', 'report'];
    private const AUTOMATION_TRIGGERS = ['manual', 'schedule.completed', 'sync.failed', 'backup.completed'];
    private const AUTOMATION_ACTIONS = ['enqueue_sync', 'request_backup_l1', 'request_backup_l2', 'request_backup_l3', 'generate_report', 'write_audit'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Redactor $redactor,
    ) {}

    public function schedules(): array
    {
        return DB::table('scheduled_tasks')->where('tenant_id', $this->context->id())->orderBy('name')->get()->map(fn ($row) => $this->objectToArray($row))->all();
    }

    public function saveSchedule(?int $id, array $data, int $actorUserId): array
    {
        $type = (string) ($data['task_type'] ?? '');
        $schedule = (string) ($data['schedule'] ?? '');
        $timezone = (string) ($data['timezone'] ?? 'UTC');
        if (! in_array($type, self::TASK_TYPES, true)) {
            throw ValidationException::withMessages(['task_type' => 'Unsupported scheduled task type.']);
        }
        $next = $this->nextRunAt($schedule, $timezone, now());
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'task_type' => $type,
            'schedule' => $schedule,
            'timezone' => $timezone,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'payload' => json_encode($this->redactor->redact((array) ($data['payload'] ?? [])), JSON_THROW_ON_ERROR),
            'retry_policy' => json_encode($this->normalizeRetryPolicy((array) ($data['retry_policy'] ?? [])), JSON_THROW_ON_ERROR),
            'next_run_at' => $next,
            'updated_at' => now(),
        ];
        if ($payload['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Schedule name is required.']);
        }
        if ($id) {
            $exists = DB::table('scheduled_tasks')->where('tenant_id', $this->context->id())->where('id', $id)->exists();
            if (! $exists) {
                throw (new ModelNotFoundException)->setModel('scheduled_task');
            }
            DB::table('scheduled_tasks')->where('tenant_id', $this->context->id())->where('id', $id)->update($payload);
        } else {
            $payload['tenant_id'] = $this->context->id();
            $payload['created_by_user_id'] = $actorUserId;
            $payload['created_at'] = now();
            $id = DB::table('scheduled_tasks')->insertGetId($payload);
        }
        $this->audit($actorUserId, 'schedule.saved', 'scheduled_task', $id, ['task_type' => $type, 'schedule' => $schedule, 'timezone' => $timezone]);

        return $this->row('scheduled_tasks', $id);
    }

    public function dispatchDueSchedules(?Carbon $at = null): int
    {
        $at ??= now();
        $tasks = DB::table('scheduled_tasks')->where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', $at)->orderBy('id')->get();
        $count = 0;
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task, $at, &$count): void {
                $fresh = DB::table('scheduled_tasks')->where('id', $task->id)->lockForUpdate()->first();
                if (! $fresh || ! $fresh->enabled || Carbon::parse($fresh->next_run_at)->isAfter($at)) {
                    return;
                }
                $operation = $this->createOperationForTenant(
                    (int) $fresh->tenant_id,
                    (int) $fresh->created_by_user_id,
                    'scheduled.'.(string) $fresh->task_type,
                    'scheduled_task',
                    (string) $fresh->id,
                    json_decode($fresh->payload ?: '[]', true) ?: [],
                    (int) (($this->json($fresh->retry_policy)['max_attempts'] ?? 1)),
                );
                DB::table('scheduled_tasks')->where('id', $fresh->id)->update([
                    'last_run_at' => $at,
                    'last_status' => 'queued',
                    'last_result' => json_encode(['operation_id' => $operation['id']], JSON_THROW_ON_ERROR),
                    'last_failure' => null,
                    'next_run_at' => $this->nextRunAt((string) $fresh->schedule, (string) $fresh->timezone, $at),
                    'updated_at' => now(),
                ]);
                $count++;
            });
        }

        return $count;
    }

    public function automations(): array
    {
        return DB::table('automation_rules')->where('tenant_id', $this->context->id())->orderBy('name')->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['conditions', 'actions']))->all();
    }

    public function saveAutomation(?int $id, array $data, int $actorUserId): array
    {
        $trigger = (string) ($data['trigger'] ?? '');
        $actions = array_values((array) ($data['actions'] ?? []));
        if (! in_array($trigger, self::AUTOMATION_TRIGGERS, true)) {
            throw ValidationException::withMessages(['trigger' => 'Unsupported automation trigger.']);
        }
        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => 'At least one controlled action is required.']);
        }
        foreach ($actions as $action) {
            $type = is_array($action) ? ($action['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, self::AUTOMATION_ACTIONS, true)) {
                throw ValidationException::withMessages(['actions' => 'Automation contains an unsupported action.']);
            }
        }
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'trigger' => $trigger,
            'conditions' => json_encode($this->redactor->redact((array) ($data['conditions'] ?? [])), JSON_THROW_ON_ERROR),
            'actions' => json_encode($this->redactor->redact($actions), JSON_THROW_ON_ERROR),
            'approval_required' => (bool) ($data['approval_required'] ?? false),
            'status' => (string) ($data['status'] ?? 'active'),
            'updated_at' => now(),
        ];
        if ($payload['name'] === '' || ! in_array($payload['status'], ['active', 'paused'], true)) {
            throw ValidationException::withMessages(['automation' => 'Automation name/status is invalid.']);
        }
        if ($id) {
            $exists = DB::table('automation_rules')->where('tenant_id', $this->context->id())->where('id', $id)->exists();
            if (! $exists) {
                throw (new ModelNotFoundException)->setModel('automation_rule');
            }
            DB::table('automation_rules')->where('tenant_id', $this->context->id())->where('id', $id)->update($payload);
        } else {
            $payload['tenant_id'] = $this->context->id();
            $payload['created_by_user_id'] = $actorUserId;
            $payload['created_at'] = now();
            $id = DB::table('automation_rules')->insertGetId($payload);
        }
        $this->audit($actorUserId, 'automation.saved', 'automation_rule', $id, ['trigger' => $trigger]);

        return $this->decodeColumns($this->row('automation_rules', $id), ['conditions', 'actions']);
    }

    public function triggerAutomation(int $id, array $triggerPayload, int $actorUserId): array
    {
        $rule = DB::table('automation_rules')->where('tenant_id', $this->context->id())->where('id', $id)->where('status', 'active')->first();
        if (! $rule) {
            throw (new ModelNotFoundException)->setModel('automation_rule');
        }
        if (! $this->conditionsMatch($this->json($rule->conditions), $triggerPayload)) {
            return ['matched' => false, 'run_id' => null];
        }
        $correlationId = (string) Str::uuid();
        $status = $rule->approval_required ? 'awaiting_approval' : 'running';
        $runId = DB::table('automation_runs')->insertGetId([
            'tenant_id' => $this->context->id(),
            'automation_rule_id' => $rule->id,
            'correlation_id' => $correlationId,
            'status' => $status,
            'trigger_payload' => json_encode($this->redactor->redact($triggerPayload), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($rule->approval_required) {
            $this->audit($actorUserId, 'automation.awaiting_approval', 'automation_run', $runId, ['correlation_id' => $correlationId]);
            return ['matched' => true, 'run_id' => $runId, 'status' => 'awaiting_approval'];
        }

        return $this->executeAutomationRun($runId, $actorUserId);
    }

    public function approveAutomationRun(int $runId, int $actorUserId): array
    {
        $run = DB::table('automation_runs')->where('tenant_id', $this->context->id())->where('id', $runId)->where('status', 'awaiting_approval')->first();
        if (! $run) {
            throw (new ModelNotFoundException)->setModel('automation_run');
        }
        DB::table('automation_runs')->where('id', $runId)->update(['approved_at' => now(), 'approved_by_user_id' => $actorUserId, 'status' => 'running', 'updated_at' => now()]);

        return $this->executeAutomationRun($runId, $actorUserId);
    }

    public function operations(array $filters = []): array
    {
        $query = DB::table('operation_executions')->where('tenant_id', $this->context->id());
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', 'like', $filters['type'].'%');
        }
        return $query->orderByDesc('id')->limit(200)->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['payload', 'result']))->all();
    }

    public function operation(int $id): array
    {
        $operation = $this->row('operation_executions', $id);
        $logs = DB::table('operation_logs')->where('tenant_id', $this->context->id())->where('operation_execution_id', $id)->orderBy('id')->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['context']))->all();
        return ['operation' => $this->decodeColumns($operation, ['payload', 'result']), 'logs' => $logs];
    }

    public function cancelOperation(int $id, int $actorUserId): array
    {
        $operation = DB::table('operation_executions')->where('tenant_id', $this->context->id())->where('id', $id)->first();
        if (! $operation) {
            throw (new ModelNotFoundException)->setModel('operation_execution');
        }
        if (! $operation->safe_to_cancel || ! in_array($operation->status, ['queued', 'retrying'], true)) {
            throw ValidationException::withMessages(['operation' => 'Operation can no longer be safely cancelled.']);
        }
        DB::table('operation_executions')->where('id', $id)->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        $this->log((int) $operation->tenant_id, $id, (string) $operation->correlation_id, 'warning', 'Operation cancelled before unsafe mutation.', ['actor_user_id' => $actorUserId]);
        $this->audit($actorUserId, 'operation.cancelled', 'operation_execution', $id, []);

        return $this->row('operation_executions', $id);
    }

    public function retryOperation(int $id, int $actorUserId): array
    {
        $operation = DB::table('operation_executions')->where('tenant_id', $this->context->id())->where('id', $id)->first();
        if (! $operation) {
            throw (new ModelNotFoundException)->setModel('operation_execution');
        }
        if (! in_array($operation->status, ['failed', 'cancelled'], true) || $operation->attempts >= $operation->max_attempts) {
            throw ValidationException::withMessages(['operation' => 'Operation is not retryable.']);
        }
        DB::table('operation_executions')->where('id', $id)->update(['status' => 'retrying', 'attempts' => $operation->attempts + 1, 'failure' => null, 'completed_at' => null, 'updated_at' => now()]);
        $this->audit($actorUserId, 'operation.retry_requested', 'operation_execution', $id, ['type' => $operation->type]);

        if ($operation->type === 'report.export') {
            $export = DB::table('report_exports')->where('tenant_id', $this->context->id())->where('operation_execution_id', $id)->first();
            if ($export) {
                GenerateReportExport::dispatch($this->context->id(), (int) $export->id);
            }
        } elseif (str_starts_with((string) $operation->type, 'sync.') && app()->bound(SyncOperationsGateway::class)) {
            try {
                $result = app(SyncOperationsGateway::class)->retry($this->context->id(), $this->decodeColumns($this->objectToArray($operation), ['payload', 'result']));
                DB::table('operation_executions')->where('id', $id)->update(['status' => 'running', 'result' => json_encode($this->redactor->redact($result), JSON_THROW_ON_ERROR), 'started_at' => now(), 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->failOperation($id, $e->getMessage());
            }
        } else {
            $this->failOperation($id, 'Retry handler capability is not integrated for this operation type.');
        }

        return $this->row('operation_executions', $id);
    }

    public function syncOperations(): array
    {
        return $this->operations(['type' => 'sync.']);
    }

    public function requestBackup(string $level, ?string $siteKey, array $manifest, int $actorUserId): array
    {
        if (! in_array($level, ['L1', 'L2', 'L3'], true)) {
            throw ValidationException::withMessages(['level' => 'Backup level must be L1, L2, or L3.']);
        }
        $risk = match ($level) { 'L1' => 'low', 'L2' => 'medium', 'L3' => 'high' };
        $approvalRequired = $level === 'L3';
        $operation = $this->createOperation('backup.'.strtolower($level), 'backup', null, ['site_key' => $siteKey, 'level' => $level, 'manifest' => $manifest], 3, $actorUserId);
        $backupId = DB::table('backups')->insertGetId([
            'tenant_id' => $this->context->id(), 'requested_by_user_id' => $actorUserId, 'site_key' => $siteKey, 'level' => $level,
            'manifest' => json_encode($this->redactor->redact($manifest), JSON_THROW_ON_ERROR), 'status' => $approvalRequired ? 'awaiting_approval' : 'requested',
            'risk_level' => $risk, 'approval_required' => $approvalRequired, 'operation_execution_id' => $operation['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('operation_executions')->where('id', $operation['id'])->update(['subject_id' => (string) $backupId]);
        if (! $approvalRequired) {
            $this->startBackup($backupId, $actorUserId);
        }
        $this->audit($actorUserId, 'backup.requested', 'backup', $backupId, ['level' => $level, 'risk_level' => $risk]);

        return $this->backup($backupId);
    }

    public function approveBackup(int $backupId, int $actorUserId): array
    {
        $backup = DB::table('backups')->where('tenant_id', $this->context->id())->where('id', $backupId)->where('status', 'awaiting_approval')->first();
        if (! $backup) {
            throw (new ModelNotFoundException)->setModel('backup');
        }
        DB::table('backups')->where('id', $backupId)->update(['approved_by_user_id' => $actorUserId, 'status' => 'requested', 'updated_at' => now()]);
        $this->startBackup($backupId, $actorUserId);
        return $this->backup($backupId);
    }

    public function requestRestore(int $backupId, int $actorUserId): array
    {
        $backup = DB::table('backups')->where('tenant_id', $this->context->id())->where('id', $backupId)->where('status', 'succeeded')->first();
        if (! $backup) {
            throw (new ModelNotFoundException)->setModel('backup');
        }
        $operation = $this->createOperation('restore', 'backup', (string) $backupId, [], 2, $actorUserId);
        $restoreId = DB::table('restore_requests')->insertGetId([
            'tenant_id' => $this->context->id(), 'backup_id' => $backupId, 'requested_by_user_id' => $actorUserId,
            'status' => 'awaiting_approval', 'risk_level' => 'high', 'operation_execution_id' => $operation['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($actorUserId, 'restore.requested', 'restore_request', $restoreId, ['backup_id' => $backupId]);
        return $this->row('restore_requests', $restoreId);
    }

    public function approveRestore(int $restoreId, int $actorUserId): array
    {
        $restore = DB::table('restore_requests')->where('tenant_id', $this->context->id())->where('id', $restoreId)->where('status', 'awaiting_approval')->first();
        if (! $restore) {
            throw (new ModelNotFoundException)->setModel('restore_request');
        }
        DB::table('restore_requests')->where('id', $restoreId)->update(['approved_by_user_id' => $actorUserId, 'status' => 'requested', 'updated_at' => now()]);
        $operation = DB::table('operation_executions')->where('id', $restore->operation_execution_id)->first();
        if (! app()->bound(ConnectorBackupGateway::class)) {
            $this->failOperation((int) $operation->id, 'WordPress connector restore capability is not integrated.');
            DB::table('restore_requests')->where('id', $restoreId)->update(['status' => 'blocked', 'updated_at' => now()]);
        } else {
            try {
                $result = app(ConnectorBackupGateway::class)->startRestore($this->context->id(), (int) $restore->backup_id, (string) $operation->correlation_id);
                DB::table('operation_executions')->where('id', $operation->id)->update(['status' => 'running', 'started_at' => now(), 'safe_to_cancel' => false, 'result' => json_encode($this->redactor->redact($result), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                DB::table('restore_requests')->where('id', $restoreId)->update(['status' => 'running', 'updated_at' => now()]);
            } catch (Throwable $e) {
                $this->failOperation((int) $operation->id, $e->getMessage());
                DB::table('restore_requests')->where('id', $restoreId)->update(['status' => 'failed', 'updated_at' => now()]);
            }
        }
        return $this->row('restore_requests', $restoreId);
    }

    public function backups(): array
    {
        return DB::table('backups')->where('tenant_id', $this->context->id())->orderByDesc('id')->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['manifest', 'verification']))->all();
    }

    public function logs(array $filters = []): array
    {
        $query = DB::table('operation_logs')->where('tenant_id', $this->context->id());
        if (! empty($filters['level'])) {
            $query->where('level', $filters['level']);
        }
        if (! empty($filters['correlation_id'])) {
            $query->where('correlation_id', $filters['correlation_id']);
        }
        if (! empty($filters['q'])) {
            $query->where('message', 'like', '%'.$filters['q'].'%');
        }
        return $query->orderByDesc('occurred_at')->limit(500)->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['context']))->all();
    }

    public function diagnostics(): array
    {
        return [
            'failed_operations' => DB::table('operation_executions')->where('tenant_id', $this->context->id())->where('status', 'failed')->orderByDesc('id')->limit(100)->get()->map(fn ($row) => $this->decodeColumns($this->objectToArray($row), ['payload', 'result']))->all(),
            'recent_audit' => AuditEvent::query()->orderByDesc('occurred_at')->limit(100)->get()->toArray(),
        ];
    }

    public function report(string $type): array
    {
        return match ($type) {
            'members' => ['type' => $type, 'active' => DB::table('tenant_memberships')->where('tenant_id', $this->context->id())->where('status', 'active')->count(), 'inactive' => DB::table('tenant_memberships')->where('tenant_id', $this->context->id())->where('status', 'inactive')->count()],
            'operations' => ['type' => $type, 'counts' => DB::table('operation_executions')->where('tenant_id', $this->context->id())->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status')->all()],
            'backups' => ['type' => $type, 'counts' => DB::table('backups')->where('tenant_id', $this->context->id())->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status')->all()],
            'audit' => ['type' => $type, 'events' => AuditEvent::query()->count(), 'latest_at' => AuditEvent::query()->max('occurred_at')],
            default => throw ValidationException::withMessages(['report_type' => 'Unsupported report type.']),
        };
    }

    public function queueExport(string $type, array $filters, string $format, int $actorUserId): array
    {
        if (! in_array($type, ['members', 'operations', 'backups', 'audit'], true) || $format !== 'csv') {
            throw ValidationException::withMessages(['export' => 'Unsupported report type or export format.']);
        }
        $operation = $this->createOperation('report.export', 'report_export', null, ['report_type' => $type, 'filters' => $filters, 'format' => $format], 3, $actorUserId);
        $exportId = DB::table('report_exports')->insertGetId([
            'tenant_id' => $this->context->id(), 'requested_by_user_id' => $actorUserId, 'operation_execution_id' => $operation['id'],
            'report_type' => $type, 'filters' => json_encode($this->redactor->redact($filters), JSON_THROW_ON_ERROR), 'format' => $format,
            'status' => 'queued', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('operation_executions')->where('id', $operation['id'])->update(['subject_id' => (string) $exportId]);
        GenerateReportExport::dispatch($this->context->id(), $exportId);
        $this->audit($actorUserId, 'report.export_queued', 'report_export', $exportId, ['report_type' => $type]);
        return $this->decodeColumns($this->row('report_exports', $exportId), ['filters']);
    }

    public function export(int $id): array
    {
        return $this->decodeColumns($this->row('report_exports', $id), ['filters']);
    }

    private function executeAutomationRun(int $runId, int $actorUserId): array
    {
        $run = DB::table('automation_runs')->where('tenant_id', $this->context->id())->where('id', $runId)->first();
        $rule = DB::table('automation_rules')->where('tenant_id', $this->context->id())->where('id', $run->automation_rule_id)->first();
        $operationIds = [];
        foreach ($this->json($rule->actions) as $action) {
            $type = (string) $action['type'];
            if ($type === 'write_audit') {
                $this->audit($actorUserId, 'automation.action', 'automation_rule', $rule->id, ['message' => $action['message'] ?? 'automation action']);
                continue;
            }
            $operationType = match ($type) {
                'enqueue_sync' => 'sync.automation',
                'request_backup_l1' => 'backup.l1',
                'request_backup_l2' => 'backup.l2',
                'request_backup_l3' => 'backup.l3',
                'generate_report' => 'report.automation',
            };
            $operationIds[] = $this->createOperation($operationType, 'automation_run', (string) $runId, (array) ($action['payload'] ?? []), 3, $actorUserId)['id'];
        }
        DB::table('automation_runs')->where('id', $runId)->update(['status' => 'succeeded', 'result' => json_encode(['operation_ids' => $operationIds], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->audit($actorUserId, 'automation.executed', 'automation_run', $runId, ['operation_ids' => $operationIds]);
        return ['matched' => true, 'run_id' => $runId, 'status' => 'succeeded', 'operation_ids' => $operationIds];
    }

    private function startBackup(int $backupId, int $actorUserId): void
    {
        $backup = DB::table('backups')->where('tenant_id', $this->context->id())->where('id', $backupId)->first();
        $operation = DB::table('operation_executions')->where('tenant_id', $this->context->id())->where('id', $backup->operation_execution_id)->first();
        if (! app()->bound(ConnectorBackupGateway::class)) {
            $this->failOperation((int) $operation->id, 'WordPress connector backup capability is not integrated.');
            DB::table('backups')->where('id', $backupId)->update(['status' => 'blocked', 'updated_at' => now()]);
            return;
        }
        try {
            $result = app(ConnectorBackupGateway::class)->startBackup($this->context->id(), $backup->site_key, $backup->level, $this->json($backup->manifest), (string) $operation->correlation_id);
            DB::table('operation_executions')->where('id', $operation->id)->update(['status' => 'running', 'started_at' => now(), 'safe_to_cancel' => false, 'result' => json_encode($this->redactor->redact($result), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            DB::table('backups')->where('id', $backupId)->update(['status' => 'running', 'updated_at' => now()]);
            $this->audit($actorUserId, 'backup.started', 'backup', $backupId, ['correlation_id' => $operation->correlation_id]);
        } catch (Throwable $e) {
            $this->failOperation((int) $operation->id, $e->getMessage());
            DB::table('backups')->where('id', $backupId)->update(['status' => 'failed', 'updated_at' => now()]);
        }
    }

    private function createOperation(string $type, ?string $subjectType, ?string $subjectId, array $payload, int $maxAttempts, ?int $actorUserId): array
    {
        return $this->createOperationForTenant($this->context->id(), $actorUserId, $type, $subjectType, $subjectId, $payload, $maxAttempts);
    }

    private function createOperationForTenant(int $tenantId, ?int $actorUserId, string $type, ?string $subjectType, ?string $subjectId, array $payload, int $maxAttempts): array
    {
        $correlationId = (string) Str::uuid();
        $id = DB::table('operation_executions')->insertGetId([
            'tenant_id' => $tenantId, 'requested_by_user_id' => $actorUserId, 'type' => $type, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'correlation_id' => $correlationId, 'status' => 'queued', 'progress' => 0, 'attempts' => 0, 'max_attempts' => max(1, $maxAttempts),
            'safe_to_cancel' => true, 'payload' => json_encode($this->redactor->redact($payload), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->log($tenantId, $id, $correlationId, 'info', 'Operation queued.', ['type' => $type]);
        return ['id' => $id, 'correlation_id' => $correlationId, 'status' => 'queued'];
    }

    private function failOperation(int $id, string $failure): void
    {
        $operation = DB::table('operation_executions')->where('id', $id)->first();
        DB::table('operation_executions')->where('id', $id)->update(['status' => 'failed', 'failure' => $failure, 'completed_at' => now(), 'updated_at' => now()]);
        if ($operation) {
            $this->log((int) $operation->tenant_id, $id, (string) $operation->correlation_id, 'error', 'Operation failed.', ['failure' => $failure]);
        }
    }

    private function log(int $tenantId, ?int $operationId, string $correlationId, string $level, string $message, array $context): void
    {
        DB::table('operation_logs')->insert([
            'tenant_id' => $tenantId, 'operation_execution_id' => $operationId, 'correlation_id' => $correlationId, 'level' => $level,
            'message' => $message, 'context' => json_encode($this->redactor->redact($context), JSON_THROW_ON_ERROR), 'occurred_at' => now(),
        ]);
    }

    private function backup(int $id): array
    {
        return $this->decodeColumns($this->row('backups', $id), ['manifest', 'verification']);
    }

    private function row(string $table, int $id): array
    {
        $row = DB::table($table)->where('tenant_id', $this->context->id())->where('id', $id)->first();
        if (! $row) {
            throw (new ModelNotFoundException)->setModel($table);
        }
        return $this->objectToArray($row);
    }

    private function nextRunAt(string $schedule, string $timezone, Carbon $from): Carbon
    {
        try {
            $local = $from->copy()->setTimezone($timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages(['timezone' => 'Invalid timezone.']);
        }
        $next = match ($schedule) {
            'hourly' => $local->copy()->addHour()->startOfHour(),
            'daily' => $local->copy()->addDay()->startOfDay(),
            'weekly' => $local->copy()->addWeek()->startOfDay(),
            'monthly' => $local->copy()->addMonth()->startOfDay(),
            default => $this->intervalNextRun($schedule, $local),
        };
        return $next->utc();
    }

    private function intervalNextRun(string $schedule, Carbon $local): Carbon
    {
        if (! preg_match('/^every:(\d+):minutes$/', $schedule, $matches)) {
            throw ValidationException::withMessages(['schedule' => 'Use hourly, daily, weekly, monthly, or every:N:minutes.']);
        }
        $minutes = (int) $matches[1];
        if ($minutes < 5 || $minutes > 10080) {
            throw ValidationException::withMessages(['schedule' => 'Minute interval must be between 5 and 10080.']);
        }
        return $local->copy()->addMinutes($minutes);
    }

    private function normalizeRetryPolicy(array $policy): array
    {
        return [
            'max_attempts' => min(10, max(1, (int) ($policy['max_attempts'] ?? 3))),
            'backoff_seconds' => min(86400, max(0, (int) ($policy['backoff_seconds'] ?? 60))),
        ];
    }

    private function conditionsMatch(array $conditions, array $payload): bool
    {
        foreach ($conditions as $field => $expected) {
            if (data_get($payload, (string) $field) !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function objectToArray(object $row): array
    {
        return get_object_vars($row);
    }

    private function decodeColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $row) && is_string($row[$column])) {
                $row[$column] = json_decode($row[$column], true);
            }
        }
        return $this->redactor->redact($row);
    }

    private function audit(int $actorUserId, string $event, string $subjectType, int|string $subjectId, array $metadata): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actorUserId, 'event' => $event, 'subject_type' => $subjectType, 'subject_id' => (string) $subjectId,
            'metadata' => $this->redactor->redact($metadata), 'occurred_at' => now(),
        ]);
    }
}
