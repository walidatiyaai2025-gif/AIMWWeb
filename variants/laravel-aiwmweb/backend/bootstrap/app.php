<?php

use App\Billing\Exceptions\BillingConflictException;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\InvalidProviderSignatureException;
use App\Billing\Exceptions\QuotaExceededException;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $proxies = array_values(array_filter(array_map(
            static fn (string $proxy): string => trim($proxy),
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')),
        )));

        $middleware->trustProxies(
            at: $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->append(RequestCorrelation::class);
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);
        $middleware->validateCsrfTokens(except: ['api/v1/billing/webhooks/paypal']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('health/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (EntitlementDeniedException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'ENTITLEMENT_DENIED'], 403) : null);
        $exceptions->render(fn (QuotaExceededException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'QUOTA_EXCEEDED'], 429) : null);
        $exceptions->render(fn (InvalidProviderSignatureException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => 'Invalid provider signature.', 'code' => 'INVALID_PROVIDER_SIGNATURE'], 401) : null);
        $exceptions->render(fn (BillingConflictException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'BILLING_CONFLICT'], 409) : null);
    })->create();
