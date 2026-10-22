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

final class AnthropicCompatibleProviderClient implements AiProviderClient
{
    public function __construct(private readonly ProviderFailureMapper $failures) {}

    public function adapterKey(): string
    {
        return 'anthropic-compatible';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function check(AiProviderProfile $provider, ?string $apiKey, AiModelProfile $model): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers($apiKey))
                ->timeout($provider->timeout_seconds)
                ->get($this->endpoint($provider).'/models/'.rawurlencode($model->model_key));
        } catch (ConnectionException) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => 'Anthropic-compatible provider could not be reached.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['state' => ProviderReadiness::InvalidCredentials->value, 'message' => 'Provider rejected the configured credential.'];
        }
        if ($response->status() === 429) {
            return ['state' => ProviderReadiness::RateLimited->value, 'message' => 'Provider rate limit was reached.'];
        }
        if ($response->status() === 404) {
            return ['state' => ProviderReadiness::ModelUnavailable->value, 'message' => 'Configured model is unavailable.'];
        }
        if (! $response->successful()) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => "Provider health request failed with HTTP {$response->status()}."];
        }

        return ['state' => ProviderReadiness::Ready->value, 'message' => null];
    }

    public function generate(AiProviderProfile $provider, AiModelProfile $model, ?string $apiKey, array $request): array
    {
        $payload = [
            'model' => $model->model_key,
            'max_tokens' => min(
                (int) $request['max_output_tokens'],
                (int) ($model->max_output_tokens ?: $request['max_output_tokens']),
            ),
            'temperature' => $request['temperature'],
            'messages' => [['role' => 'user', 'content' => $request['user']]],
        ];
        if (filled($request['system'] ?? null)) {
            $payload['system'] = $request['system'];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders($this->headers($apiKey))
                ->timeout($provider->timeout_seconds)
                ->post($this->endpoint($provider).'/messages', $payload);
        } catch (ConnectionException) {
            throw new AiPlatformException(AiFailureKind::Timeout, 'Anthropic-compatible request timed out.', true, 504);
        }

        if (! $response->successful()) {
            $this->failures->throwForResponse($response);
        }

        $content = $response->json('content.0.text');
        if (! is_string($content) || trim($content) === '') {
            throw new AiPlatformException(AiFailureKind::InvalidOutput, 'Provider returned an empty response.', false, 422);
        }

        return [
            'content' => $content,
            'input_units' => (int) $response->json('usage.input_tokens', 0),
            'output_units' => (int) $response->json('usage.output_tokens', 0),
            'actual_cost' => null,
            'provider_request_id' => $response->header('request-id'),
        ];
    }

    private function endpoint(AiProviderProfile $provider): string
    {
        return rtrim($provider->endpoint ?: 'https://api.anthropic.com/v1', '/');
    }

    private function headers(?string $apiKey): array
    {
        $headers = ['anthropic-version' => '2023-06-01'];
        if (filled($apiKey)) {
            $headers['x-api-key'] = $apiKey;
        }

        return $headers;
    }
}
