<?php

namespace App\AI\Platform\Contracts;

interface AiGenerator
{
    /**
     * @param  array{
     *     workflow:string,
     *     prompt_key?:string,
     *     variables?:array,
     *     user_prompt?:string,
     *     system_prompt?:string,
     *     output_schema?:array,
     *     model?:string,
     *     temperature?:float,
     *     max_output_tokens?:int,
     *     site_id?:int|null
     * }  $request
     * @return array{correlation_id:string,provider:string,model:string,content:string,structured:?array}
     */
    public function generate(array $request): array;
}
