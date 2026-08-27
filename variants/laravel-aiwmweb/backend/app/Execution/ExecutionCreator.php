<?php

namespace App\Execution;

use App\Models\Approval;
use App\Models\Execution;
use App\Models\Suggestion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExecutionCreator
{
    public function create(Approval $approval, int $actorUserId): array
    {
        try {
            return DB::transaction(function () use ($approval, $actorUserId): array {
                $locked = Approval::query()->lockForUpdate()->findOrFail($approval->id);
                if ($existing = Execution::query()->where('approval_id', $locked->id)->first()) {
                    return [$existing, false];
                }
                $suggestion = Suggestion::query()->findOrFail($locked->suggestion_id);
                $execution = Execution::query()->create([
                    'operation_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
                    'correlation_id' => (string) Str::uuid(), 'site_id' => $suggestion->site_id,
                    'approval_id' => $locked->id, 'actor_user_id' => $actorUserId,
                ]);

                return [$execution, true];
            }, 3);
        } catch (QueryException $exception) {
            if ($existing = Execution::query()->where('approval_id', $approval->id)->first()) {
                return [$existing, false];
            }
            throw $exception;
        }
    }
}
