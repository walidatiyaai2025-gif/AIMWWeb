<?php

namespace App\AI\Durable;

use Illuminate\Http\Exceptions\HttpResponseException;

final class DeferredGeneration extends HttpResponseException
{
    public function __construct(array $batch)
    {
        parent::__construct(response()->json(['data' => $batch, 'status_url' => $batch['status_url']], 202));
    }
}
