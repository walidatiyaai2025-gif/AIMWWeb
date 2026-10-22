<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Contracts\AiProviderClient;
use App\AI\Platform\Providers\AnthropicCompatibleProviderClient;
use App\AI\Platform\Providers\GeminiProviderClient;
use App\AI\Platform\Providers\OpenAiCompatibleProviderClient;
use InvalidArgumentException;

final class ProviderRegistry
{
    /** @var array<string, AiProviderClient> */
    private array $clients;

    public function __construct(
        OpenAiCompatibleProviderClient $openAi,
        GeminiProviderClient $gemini,
        AnthropicCompatibleProviderClient $anthropic,
    ) {
        $this->clients = [
            $openAi->adapterKey() => $openAi,
            $gemini->adapterKey() => $gemini,
            $anthropic->adapterKey() => $anthropic,
        ];
    }

    public function get(string $adapterKey): AiProviderClient
    {
        return $this->clients[$adapterKey]
            ?? throw new InvalidArgumentException("Unsupported AI provider adapter: {$adapterKey}");
    }

    public function adapterKeys(): array
    {
        return array_keys($this->clients);
    }
}
