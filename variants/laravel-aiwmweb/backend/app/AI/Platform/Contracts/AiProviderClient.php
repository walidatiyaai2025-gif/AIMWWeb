<?php

namespace App\AI\Platform\Contracts;

use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;

interface AiProviderClient
{
    public function adapterKey(): string;

    public function requiresApiKey(): bool;

    /**
     * @return array{state:string,message:?string}
     */
    public function check(AiProviderProfile $provider, ?string $apiKey, AiModelProfile $model): array;

    /**
     * @param  array{system:?string,user:string,temperature:float,max_output_tokens:int,output_schema:?array}  $request
     * @return array{content:string,input_units:int,output_units:int,actual_cost:?float,provider_request_id:?string}
     */
    public function generate(AiProviderProfile $provider, AiModelProfile $model, ?string $apiKey, array $request): array;
}
