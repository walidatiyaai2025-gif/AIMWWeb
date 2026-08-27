<?php

namespace App\AI;

use App\Models\AiProviderConfig;

interface AiProvider
{
    public function suggest(AiProviderConfig $config, array $content, array $finding): array;
}
