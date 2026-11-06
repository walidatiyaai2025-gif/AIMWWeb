<?php

namespace App\AI\Durable;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ExecutionPermit
{
    private ?array $claim = null;

    private bool $used = false;

    public function enter(array $claim): void
    {
        $this->claim = $claim;
        $this->used = false;
    }

    public function leave(): void
    {
        $this->claim = null;
        $this->used = false;
    }

    public function active(): bool
    {
        return $this->claim !== null;
    }

    public function beforeProviderCall(?AiProviderProfile $provider = null, ?AiModelProfile $model = null, array $request = []): void
    {
        if (! config('cpanel.enabled')) {
            return;
        }
        if (! config('cpanel.ai_enabled') || $this->claim === null || $this->used) {
            throw new RuntimeException('AI execution requires an unused durable worker permit.');
        }
        if ($model !== null) {
            if ($model->input_cost_per_million === null || $model->output_cost_per_million === null || (float) $model->input_cost_per_million < 0 || (float) $model->output_cost_per_million < 0) {
                throw new RuntimeException('Model pricing must be configured before cPanel execution.');
            }
            $inputBound = strlen(json_encode($request, JSON_THROW_ON_ERROR)) + 4096;
            $estimate = ($inputBound * (float) $model->input_cost_per_million + (int) ($request['max_output_tokens'] ?? 8000) * (float) $model->output_cost_per_million) / 1000000;
            if ($model->currency !== 'USD' || $estimate > (float) config('cpanel.call_reserve_usd')) {
                throw new RuntimeException('Model cost exceeds the reserved call budget.');
            }
        }
        $claim = $this->claim;
        DB::transaction(function () use ($claim, $provider, $model): void {
            $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null || $gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence']
                || $gate->item_id !== $claim['item_id'] || $gate->circuit !== 'closed'
                || $gate->expires_at === null || now()->gte($gate->expires_at)) {
                throw new RuntimeException('AI execution lease lost.');
            }
            // A pause/cancel committed while preparing the request must stop an
            // unbilled provider call, even if the worker still holds its lease.
            $batchEligible = DB::table('durable_ai_batches')->where('id', $claim['batch_id'])
                ->where('state', 'pending')->whereNull('cancelled_at')->exists();
            if (! $batchEligible) {
                throw new RuntimeException('AI batch is no longer eligible for provider execution.');
            }
            $updated = DB::table('durable_ai_items')->where('id', $claim['item_id'])
                ->where('batch_id', $claim['batch_id'])->where('attempt_id', $claim['owner'])
                ->where('state', 'running')->update([
                    'sent_at' => now(), 'provider' => $provider?->provider_key, 'model' => $model?->model_key,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('AI item attempt is no longer running.');
            }
        });
        $this->used = true;
    }

    public function recordReceipt(array $metadata): void
    {
        if (! config('cpanel.enabled') || $this->claim === null) {
            return;
        }
        $claim = $this->claim;
        DB::transaction(function () use ($claim, $metadata): void {
            $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null || $gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence']
                || $gate->item_id !== $claim['item_id'] || $gate->circuit !== 'closed'
                || $gate->expires_at === null || now()->gte($gate->expires_at)) {
                throw new RuntimeException('Provider receipt fencing failed.');
            }
            $item = DB::table('durable_ai_items')->where('id', $claim['item_id'])
                ->where('batch_id', $claim['batch_id'])->where('attempt_id', $claim['owner'])
                ->where('state', 'running')->first();
            if ($item === null) {
                throw new RuntimeException('Provider receipt item attempt is no longer running.');
            }
            $updated = DB::table('durable_ai_items')->where('id', $item->id)
                ->where('attempt_id', $claim['owner'])->where('state', 'running')
                ->update(['usage' => json_encode(array_merge(json_decode($item->usage ?? '{}', true, 512, JSON_THROW_ON_ERROR), $metadata), JSON_THROW_ON_ERROR)]);
            if ($updated !== 1) {
                throw new RuntimeException('Provider receipt item was not updated.');
            }
        });
    }
}
