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

final class GeminiProviderClient implements AiProviderClient
{
    public function __construct(private readonly ProviderFailureMapper $failures) {}

    public function adapterKey(): string
    {
        return 'gemini';
    }

    public function requiresApiKey(): bool
    {
        return true;
    }

    public function check(AiProviderProfile $provider, ?string $apiKey, AiModelProfile $model): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => (string) $apiKey])
                ->timeout($provider->timeout_seconds)
                ->get($this->endpoint($provider).'/models/'.rawurlencode($model->model_key));
        } catch (ConnectionException) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => 'Gemini could not be reached.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['state' => ProviderReadiness::InvalidCredentials->value, 'message' => 'Gemini rejected the configured credential.'];
        }
        if ($response->status() === 429) {
            return ['state' => ProviderReadiness::RateLimited->value, 'message' => 'Gemini rate limit was reached.'];
        }
        if ($response->status() === 404) {
            return ['state' => ProviderReadiness::ModelUnavailable->value, 'message' => 'Configured Gemini model is unavailable.'];
        }
        if (! $response->successful()) {
            return ['state' => ProviderReadiness::Unreachable->value, 'message' => "Gemini health request failed with HTTP {$response->status()}."];
        }

        return ['state' => ProviderReadiness::Ready->value, 'message' => null];
    }

    public function generate(AiProviderProfile $provider, AiModelProfile $model, ?string $apiKey, array $request): array
    {
        $contents = [['role' => 'user', 'parts' => [['text' => $request['user']]]]];
        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $request['temperature'],
                'maxOutputTokens' => min(
                    (int) $request['max_output_tokens'],
                    (int) ($model->max_output_tokens ?: $request['max_output_tokens']),
                ),
            ],
        ];
        if (filled($request['system'] ?? null)) {
            $payload['systemInstruction'] = ['parts' => [['text' => $request['system']]]];
        }
        if (is_array($request['output_schema'] ?? null)) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema'] = $request['output_schema'];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => (string) $apiKey])
                ->timeout($provider->timeout_seconds)
                ->post(
                    $this->endpoint($provider).'/models/'.rawurlencode($model->model_key).':generateContent',
                    $payload,
                );
        } catch (ConnectionException) {
            throw new AiPlatformException(AiFailureKind::Timeout, 'Gemini request timed out.', true, 504);
        }

        if (! $response->successful()) {
            $this->failures->throwForResponse($response);
        }

        $content = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($content) || trim($content) === '') {
            throw new AiPlatformException(AiFailureKind::InvalidOutput, 'Gemini returned an empty response.', false, 422);
        }

        return [
            'content' => $content,
            'input_units' => (int) $response->json('usageMetadata.promptTokenCount', 0),
            'output_units' => (int) $response->json('usageMetadata.candidatesTokenCount', 0),
            'actual_cost' => null,
            'provider_request_id' => $response->header('x-request-id'),
        ];
    }

    private function endpoint(AiProviderProfile $provider): string
    {
        return rtrim($provider->endpoint ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
    }
}
