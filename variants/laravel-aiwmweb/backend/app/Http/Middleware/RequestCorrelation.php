<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RequestCorrelation
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->safeIdentifier($request->header('X-Request-ID')) ?? (string) Str::uuid();
        $correlationId = $this->safeIdentifier($request->header('X-Correlation-ID')) ?? $requestId;
        $startedAt = hrtime(true);

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('correlation_id', $correlationId);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            Log::error('http.request.failed', $this->context($request, $requestId, $correlationId, 500, $startedAt) + [
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Correlation-ID', $correlationId);

        Log::info('http.request.completed', $this->context(
            $request,
            $requestId,
            $correlationId,
            $response->getStatusCode(),
            $startedAt,
        ));

        return $response;
    }

    private function safeIdentifier(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value)) {
            return null;
        }

        return $value;
    }

    /** @return array<string, int|float|string|null> */
    private function context(Request $request, string $requestId, string $correlationId, int $status, int $startedAt): array
    {
        return [
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'tenant_id' => $request->attributes->get('tenant_id'),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ];
    }
}
