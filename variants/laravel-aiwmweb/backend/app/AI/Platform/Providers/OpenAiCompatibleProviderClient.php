<?php

namespace App\AI\Platform\Providers;

use App\AI\Platform\Contracts\AiProviderClient;
use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Enums\ProviderReadiness;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\AI\Platform\Support\ProviderFailureMapper;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OpenAiCompatibleProviderClient implements AiProviderClient
{
    public function __construct(private readonly ProviderFailureMapper $failures) {}

    public function adapterKey(): string
    {
        return 'openai-compatible';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function check(AiProviderProfile $provider, ?string $apiKey, AiModelProfile $model): array
    {
        try {
            $request = Http::acceptJson()->timeout($provider->timeout_seconds);
            if (filled($apiKey)) {
                $request = $request->withToken($apiKey);
            }
            $response = $request->get($this->endpoint($provider).'/models');
        } catch (ConnectionException) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => 'AI provider could not be reached.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['state' => ProviderReadiness::InvalidCredentials->value, 'message' => 'AI provider rejected the configured credential.'];
        }
        if ($response->status() === 429) {
            return ['state' => ProviderReadiness::RateLimited->value, 'message' => 'AI provider rate limit was reached.'];
        }
        if (! $response->successful()) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => "AI provider health request failed with HTTP {$response->status()}."];
        }

        $models = collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->map(fn ($value) => (string) $value);
        if ($models->isNotEmpty() && ! $models->contains($model->model_key)) {
            return ['state' => ProviderReadiness::ModelUnavailable->value, 'message' => 'Configured model is not available from the provider.'];
        }

        return ['state' => ProviderReadiness::Ready->value, 'message' => null];
    }

    public function generate(AiProviderProfile $provider, AiModelProfile $model, ?string $apiKey, array $request): array
    {
        $messages = [];
        if (filled($request['system'] ?? null)) {
            $messages[] = ['role' => 'system', 'content' => $request['system']];
        }
        $messages[] = ['role' => 'user', 'content' => $request['user']];

        $payload = [
            'model' => $model->model_key,
            'messages' => $messages,
            'temperature' => $request['temperature'],
            'max_tokens' => min(
                (int) $request['max_output_tokens'],
                (int) ($model->max_output_tokens ?: $request['max_output_tokens']),
            ),
        ];
        if (is_array($request['output_schema'] ?? null)) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $http = Http::acceptJson()->timeout($provider->timeout_seconds);
            if (filled($apiKey)) {
                $http = $http->withToken($apiKey);
            }
            $response = $http->post($this->endpoint($provider).'/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new AiPlatformException(
                AiFailureKind::Timeout,
                'AI provider connection timed out.',
                true,
                504,
            );
        }

        if (! $response->successful()) {
            $this->failures->throwForResponse($response);
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw new AiPlatformException(
                AiFailureKind::InvalidOutput,
                'AI provider returned an empty response.',
                false,
                422,
            );
        }

        return [
            'content' => $content,
            'input_units' => (int) $response->json('usage.prompt_tokens', 0),
            'output_units' => (int) $response->json('usage.completion_tokens', 0),
            'actual_cost' => null,
            'provider_request_id' => $response->header('x-request-id'),
        ];
    }

    private function endpoint(AiProviderProfile $provider): string
    {
        return rtrim($provider->endpoint ?: 'https://api.openai.com/v1', '/');
    }
}
