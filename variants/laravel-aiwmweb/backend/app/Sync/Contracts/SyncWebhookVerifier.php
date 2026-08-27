<?php

namespace App\Sync\Contracts;

use Illuminate\Http\Request;

interface SyncWebhookVerifier
{
    public function verify(Request $request): array;
}
