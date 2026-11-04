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
            if ($gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence'] || now()->gte($gate->expires_at)) {
                throw new RuntimeException('AI execution lease lost.');
            }
            DB::table('durable_ai_items')->where('id', $claim['item_id'])->where('attempt_id', $claim['owner'])
                ->where('state', 'running')->update(['sent_at' => now(), 'provider' => $provider?->provider_key, 'model' => $model?->model_key]);
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
            if ($gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence']) {
                throw new RuntimeException('Provider receipt fencing failed.');
            }
            DB::table('durable_ai_items')->where('id', $claim['item_id'])->where('state', 'running')
                ->update(['usage' => json_encode(array_merge(json_decode(DB::table('durable_ai_items')->where('id', $claim['item_id'])->value('usage') ?? '{}', true), $metadata), JSON_THROW_ON_ERROR)]);
        });
    }
}
