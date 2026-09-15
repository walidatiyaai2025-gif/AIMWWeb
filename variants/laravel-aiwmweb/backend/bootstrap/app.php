<?php

use App\Billing\Exceptions\BillingConflictException;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\InvalidProviderSignatureException;
use App\Billing\Exceptions\QuotaExceededException;
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
        $exceptions->render(fn (EntitlementDeniedException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'ENTITLEMENT_DENIED'], 403) : null);
        $exceptions->render(fn (QuotaExceededException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'QUOTA_EXCEEDED'], 429) : null);
        $exceptions->render(fn (InvalidProviderSignatureException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => 'Invalid provider signature.', 'code' => 'INVALID_PROVIDER_SIGNATURE'], 401) : null);
        $exceptions->render(fn (BillingConflictException $e, Request $r) => $r->is('api/*') ? response()->json(['message' => $e->getMessage(), 'code' => 'BILLING_CONFLICT'], 409) : null);
    })->create();
