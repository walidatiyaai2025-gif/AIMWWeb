<?php

namespace App\AI\Platform\Services;

use App\Models\AiUsageRecord;
use Illuminate\Database\Eloquent\Builder;

final class AiUsageService
{
    public function record(array $data): AiUsageRecord
    {
        return AiUsageRecord::query()->create([
            ...$data,
            'created_at' => $data['created_at'] ?? now(),
        ]);
    }

    public function report(array $filters = []): array
    {
        $query = $this->query($filters);
        $records = (clone $query)->latest('created_at')->limit(min(max((int) ($filters['take'] ?? 100), 1), 1000))->get();

        $total = (clone $query)->count();
        $success = (clone $query)->where('status', 'succeeded')->count();
        $input = (int) (clone $query)->sum('input_units');
        $output = (int) (clone $query)->sum('output_units');
        $estimated = (float) (clone $query)->sum('estimated_cost');
        $actual = (float) (clone $query)->whereNotNull('actual_cost')->sum('actual_cost');

        return [
            'summary' => [
                'total_calls' => $total,
                'successful_calls' => $success,
                'success_rate' => $total === 0 ? 0.0 : $success / $total,
                'input_units' => $input,
                'output_units' => $output,
                'estimated_cost' => $estimated,
                'actual_cost' => $actual,
            ],
            'providers' => $this->breakdown((clone $query), 'provider_key'),
            'workflows' => $this->breakdown((clone $query), 'workflow'),
            'recent' => $records->map(fn (AiUsageRecord $record) => $this->serialize($record))->all(),
            'failed' => $records->where('status', 'failed')->map(fn (AiUsageRecord $record) => $this->serialize($record))->values()->all(),
        ];
    }

    private function query(array $filters): Builder
    {
        return AiUsageRecord::query()
            ->when($filters['provider'] ?? null, fn ($query, $value) => $query->where('provider_key', $value))
            ->when($filters['model'] ?? null, fn ($query, $value) => $query->where('model_key', $value))
            ->when($filters['workflow'] ?? null, fn ($query, $value) => $query->where('workflow', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($filters['site_id'] ?? null, fn ($query, $value) => $query->where('metadata->site_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('created_at', '<=', $value));
    }

    private function breakdown(Builder $query, string $column): array
    {
        return $query->selectRaw("{$column} as name, COUNT(*) as calls, SUM(input_units) as input_units, SUM(output_units) as output_units, SUM(estimated_cost) as estimated_cost")
            ->groupBy($column)
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?: 'unknown',
                'calls' => (int) $row->calls,
                'input_units' => (int) $row->input_units,
                'output_units' => (int) $row->output_units,
                'estimated_cost' => (float) $row->estimated_cost,
            ])
            ->all();
    }

    private function serialize(AiUsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'provider' => $record->provider_key,
            'model' => $record->model_key,
            'workflow' => $record->workflow,
            'input_units' => $record->input_units,
            'output_units' => $record->output_units,
            'estimated_cost' => (float) $record->estimated_cost,
            'actual_cost' => $record->actual_cost === null ? null : (float) $record->actual_cost,
            'currency' => $record->currency,
            'status' => $record->status,
            'failure_kind' => $record->failure_kind,
            'latency_ms' => $record->latency_ms,
            'retry_count' => $record->retry_count,
            'correlation_id' => $record->correlation_id,
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
