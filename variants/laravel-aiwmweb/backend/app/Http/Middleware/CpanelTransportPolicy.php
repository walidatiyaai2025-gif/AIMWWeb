<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CpanelTransportPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cpanel.enabled')) {
            return $next($request);
        }
        $base = rtrim((string) config('app.url'), '/');
        abort_unless(str_starts_with($base, 'https://'), 503, 'Configure the HTTPS application URL before using this cPanel installation.');
        if (! $request->isSecure()) {
            return redirect($base.$request->getRequestUri(), 308);
        }
        $response = $next($request);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000');

        return $response;
    }
}
