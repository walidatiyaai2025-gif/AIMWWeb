<?php

namespace App\AI;

use App\AI\Durable\ExecutionPermit;
use App\AI\Platform\Support\ProviderFailureMapper;
use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpAiProvider implements AiProvider
{
    public function suggest(AiProviderConfig $config, array $content, array $finding): array
    {
        $payload = ['model' => $config->model, 'messages' => [
            ['role' => 'system', 'content' => 'Return JSON only with title, seo_title, seo_description, content fields. Preserve facts.'],
            ['role' => 'user', 'content' => json_encode(['content' => $content, 'finding' => $finding], JSON_THROW_ON_ERROR)],
        ], 'max_tokens' => 1500, 'response_format' => ['type' => 'json_object']];
        $model = null;
        if (config('cpanel.enabled')) {
            $base = preg_replace('~/chat/completions/?$~', '', $config->endpoint);
            $model = AiModelProfile::query()->with('provider')->where('enabled', true)->where('model_key', $config->model)
                ->whereHas('provider', fn ($query) => $query->where('enabled', true)->where('readiness_state', 'READY')->where('endpoint', $base))->first();
            if (! $model) {
                throw new RuntimeException('Legacy provider needs a matching READY model and configured pricing before cPanel execution.');
            }
        }
        app(ExecutionPermit::class)->beforeProviderCall($model?->provider, $model, ['user' => json_encode($payload, JSON_THROW_ON_ERROR), 'max_output_tokens' => 1500]);
        $response = Http::connectTimeout(5)->timeout(config('cpanel.enabled') ? config('cpanel.provider_timeout') : 45)
            ->withToken($config->encrypted_api_key)->post($config->endpoint, $payload);
        if (! $response->successful()) {
            app(ProviderFailureMapper::class)->throwForResponse($response);
        }
        $input = (int) $response->json('usage.prompt_tokens', 0);
        $output = (int) $response->json('usage.completion_tokens', 0);
        app(ExecutionPermit::class)->recordReceipt([
            'input_units' => $input, 'output_units' => $output,
            'estimated_cost' => $model ? ($input * (float) $model->input_cost_per_million + $output * (float) $model->output_cost_per_million) / 1000000 : null,
            'provider_request_id' => $response->header('x-request-id') ?: $response->json('id'),
        ]);
        $change = json_decode((string) data_get($response->json(), 'choices.0.message.content'), true);
        if (! is_array($change)) {
            throw new RuntimeException('AI provider returned invalid structured output.');
        }

        return $change;
    }
}
