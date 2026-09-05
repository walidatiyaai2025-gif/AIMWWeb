<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ErrorReadController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('platform.error', [
            'errorId' => (string) ($request->attributes->get('request_id') ?? 'N/A'),
            'correlationId' => (string) ($request->attributes->get('correlation_id') ?? 'N/A'),
            'errorTime' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
