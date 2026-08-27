<?php

namespace App\AI;

use App\Models\AiProviderConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpAiProvider implements AiProvider
{
    public function suggest(AiProviderConfig $config, array $content, array $finding): array
    {
        $response = Http::timeout(45)->withToken($config->encrypted_api_key)->post($config->endpoint, ['model' => $config->model, 'messages' => [['role' => 'system', 'content' => 'Return JSON only with title, seo_title, seo_description, content fields. Preserve facts.'], ['role' => 'user', 'content' => json_encode(['content' => $content, 'finding' => $finding], JSON_THROW_ON_ERROR)]], 'response_format' => ['type' => 'json_object']]);
        if (! $response->successful()) {
            throw new RuntimeException("AI provider failed ({$response->status()}).");
        } $text = data_get($response->json(), 'choices.0.message.content');
        $change = json_decode((string) $text, true);
        if (! is_array($change)) {
            throw new RuntimeException('AI provider returned invalid structured output.');
        }

return $change;
    }
}
