<?php

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\Billing\Exceptions\BillingConflictException;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\InvalidProviderSignatureException;
use App\Billing\Exceptions\QuotaExceededException;
use App\Http\Middleware\CpanelTransportPolicy;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

        $middleware->append(CpanelTransportPolicy::class);
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
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('tenants/*/route-api/*')) {
                return null;
            }

            $route = $request->route();
            $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
            if (! is_string($routeName) || ! str_starts_with($routeName, 'canonical.workspace.')) {
                return null;
            }

            $routeTenant = $request->route('tenant');
            $user = $request->user();
            if (! $user || ! is_string($routeTenant) || $routeTenant === '') {
                return null;
            }

            // Laravel prepares AuthorizationException as AccessDeniedHttpException
            // before render callbacks execute. Tenant middleware has also cleared its
            // request-scoped context while unwinding, so resolve the route tenant and
            // perform a scoped authoritative membership reread without bypassing
            // BelongsToTenant protections.
            $tenant = Tenant::query()->where('slug', $routeTenant)->first();
            if (! $tenant || ! hash_equals($tenant->slug, $routeTenant)) {
                return null;
            }

            $context = app(TenantContext::class);
            $context->activate($tenant);

            try {
                $membership = TenantMembership::query()
                    ->where('user_id', $user->getAuthIdentifier())
                    ->where('status', 'active')
                    ->first();

                if (! $membership) {
                    return null;
                }

                $context->activate($tenant, $membership);
                $profileUrl = $membership->hasPermission('tenant.view')
                    ? route('canonical.workspace.account-profile', ['tenant' => $tenant->slug], false)
                    : null;
            } finally {
                $context->forget();
            }

            return response()->view('platform.access-denied', [
                'profileUrl' => $profileUrl,
            ], 403);
        });
        // AiPlatformException extends RuntimeException, not HttpException.
        // Without this handler Laravel turns provider policy/timeouts into
        // misleading HTTP 500s. Use enum-only status and code; never return
        // upstream exception messages, provider bodies, tokens or stack traces.
        $exceptions->render(static function (AiPlatformException $error, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = match ($error->kind) {
                AiFailureKind::Timeout => 504,
                AiFailureKind::RateLimit, AiFailureKind::QuotaExceeded => 429,
                AiFailureKind::InvalidOutput, AiFailureKind::PolicyRejection => 422,
                AiFailureKind::Unknown => 502,
                default => 503,
            };

            return response()->json([
                'code' => $error->kind->value,
                'message' => 'AI request could not be completed. Review AI diagnostics.',
            ], $status);
        });
        $exceptions->render(fn (EntitlementDeniedException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'ENTITLEMENT_DENIED'], 403) : null);
        $exceptions->render(fn (QuotaExceededException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'QUOTA_EXCEEDED'], 429) : null);
        $exceptions->render(fn (InvalidProviderSignatureException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => 'Invalid provider signature.', 'code' => 'INVALID_PROVIDER_SIGNATURE'], 401) : null);
        $exceptions->render(fn (BillingConflictException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'BILLING_CONFLICT'], 409) : null);
        // Preserve a safe correlation reference on responses rendered by the exception
        // handler. The normal middleware response path already sets this header;
        // uncaught errors bypass that path, but the request-scoped ID still exists.
        $exceptions->respond(static function (Response $response): Response {
            $requestId = request()->attributes->get('request_id');
            if (is_string($requestId) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $requestId)) {
                $response->headers->set('X-Request-ID', $requestId);
            }

            return $response;
        });
    })->create();
