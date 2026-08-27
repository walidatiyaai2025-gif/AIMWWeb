<?php

namespace App\Tenancy;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IdempotencyService
{
    public function run(string $key, string $operation, array $request, Closure $callback): array
    {
        $hash = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($key, $operation, $hash, $callback): array {
            $record = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();
            if ($record) {
                if ($record->operation !== $operation || $record->request_hash !== $hash) {
                    throw new RuntimeException('Idempotency key reuse with a different request.');
                }
                if ($record->completed_at) {
                    return $record->response;
                }
                throw new RuntimeException('Idempotent operation is already in progress.');
            }

            $record = IdempotencyKey::query()->create(compact('key', 'operation') + ['request_hash' => $hash]);
            $response = (array) $callback();
            $record->update(['response' => $response, 'completed_at' => now()]);

            return $response;
        }, 3);
    }
}
