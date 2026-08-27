<?php

namespace App\Jobs;

use App\Operations\Redactor;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateReportExport extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(int $tenantId, public readonly int $exportId)
    {
        parent::__construct($tenantId);
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:report-export:{$this->exportId}";
    }

    public function handle(TenantContext $context, Redactor $redactor): void
    {
        $export = DB::table('report_exports')->where('tenant_id', $context->id())->where('id', $this->exportId)->first();
        if (! $export || $export->status === 'succeeded') {
            return;
        }
        $operation = DB::table('operation_executions')->where('tenant_id', $context->id())->where('id', $export->operation_execution_id)->first();
        DB::table('report_exports')->where('id', $this->exportId)->update(['status' => 'running', 'updated_at' => now()]);
        DB::table('operation_executions')->where('id', $operation->id)->update([
            'status' => 'running', 'started_at' => $operation->started_at ?: now(), 'attempts' => DB::raw('attempts + 1'), 'safe_to_cancel' => false, 'updated_at' => now(),
        ]);

        try {
            $rows = $this->rows((string) $export->report_type, $context->id(), $redactor);
            $csv = $this->csv($rows);
            $path = "tenant-{$context->id()}/exports/{$this->exportId}.csv";
            Storage::disk('local')->put($path, $csv);
            DB::table('report_exports')->where('id', $this->exportId)->update([
                'status' => 'succeeded', 'file_path' => $path, 'row_count' => count($rows), 'updated_at' => now(),
            ]);
            DB::table('operation_executions')->where('id', $operation->id)->update([
                'status' => 'succeeded', 'progress' => 100, 'result' => json_encode(['export_id' => $this->exportId, 'row_count' => count($rows)], JSON_THROW_ON_ERROR),
                'failure' => null, 'completed_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::table('report_exports')->where('id', $this->exportId)->update(['status' => 'failed', 'updated_at' => now()]);
            DB::table('operation_executions')->where('id', $operation->id)->update([
                'status' => 'failed', 'failure' => $e->getMessage(), 'completed_at' => now(), 'updated_at' => now(),
            ]);
            throw $e;
        }
    }

    private function rows(string $type, int $tenantId, Redactor $redactor): array
    {
        $rows = match ($type) {
            'members' => DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
                ->where('tenant_memberships.tenant_id', $tenantId)
                ->select('tenant_memberships.id', 'tenant_memberships.status', 'users.name', 'users.email', 'tenant_memberships.created_at')->orderBy('tenant_memberships.id')->get(),
            'operations' => DB::table('operation_executions')->where('tenant_id', $tenantId)
                ->select('id', 'type', 'correlation_id', 'status', 'progress', 'attempts', 'failure', 'created_at', 'completed_at')->orderBy('id')->get(),
            'backups' => DB::table('backups')->where('tenant_id', $tenantId)
                ->select('id', 'site_key', 'level', 'status', 'risk_level', 'approval_required', 'created_at', 'updated_at')->orderBy('id')->get(),
            'audit' => DB::table('audit_events')->where('tenant_id', $tenantId)
                ->select('id', 'actor_user_id', 'event', 'subject_type', 'subject_id', 'metadata', 'occurred_at')->orderBy('id')->get(),
            default => throw new \InvalidArgumentException('Unsupported report type.'),
        };

        return $rows->map(fn ($row) => $redactor->redact(get_object_vars($row)))->all();
    }

    private function csv(array $rows): string
    {
        if ($rows === []) {
            return "\xEF\xBB\xBF";
        }
        $headers = array_keys($rows[0]);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value, $row));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return "\xEF\xBB\xBF".$csv;
    }
}
