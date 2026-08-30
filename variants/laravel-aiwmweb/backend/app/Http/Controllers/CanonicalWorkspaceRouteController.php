<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class CanonicalWorkspaceRouteController extends Controller
{
    private const SITE_SESSION_KEY = 'canonical_site_id';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function show(Request $request, string $tenant): View
    {
        $this->authorizeRoute($request);

        return view('app');
    }

    public function showSiteBound(Request $request, string $tenant): View
    {
        $this->authorizeRoute($request);
        $this->bindSite($request);

        return view('app');
    }

    public function showSite(Request $request, string $tenant, int $site): View
    {
        $this->authorizeRoute($request);
        $model = Site::query()->findOrFail($site);
        $request->session()->put(self::SITE_SESSION_KEY, (int) $model->getKey());

        return view('app');
    }

    public function redirect(Request $request, string $tenant): RedirectResponse
    {
        $this->authorizeRoute($request);

        return redirect($this->target($request, $tenant));
    }

    public function redirectSite(Request $request, string $tenant, int $site): RedirectResponse
    {
        $this->authorizeRoute($request);
        $model = Site::query()->findOrFail($site);
        $request->session()->put(self::SITE_SESSION_KEY, (int) $model->getKey());

        return redirect($this->target($request, $tenant).'?site='.(int) $model->getKey());
    }

    private function bindSite(Request $request): Site
    {
        $requested = $request->query('site');
        if ($requested !== null && (! ctype_digit((string) $requested) || (int) $requested < 1)) {
            abort(422, 'Site context must be a positive integer.');
        }

        if ($requested !== null) {
            $site = Site::query()->findOrFail((int) $requested);
            $request->session()->put(self::SITE_SESSION_KEY, (int) $site->getKey());

            return $site;
        }

        $sessionSite = $request->session()->get(self::SITE_SESSION_KEY);
        if ($sessionSite !== null) {
            $site = Site::query()->find((int) $sessionSite);
            if (! $site) {
                $request->session()->forget(self::SITE_SESSION_KEY);
                abort(404, 'The active site is no longer available for this tenant.');
            }

            return $site;
        }

        $sites = Site::query()->orderBy('id')->limit(2)->get();
        if ($sites->count() !== 1) {
            abort(409, $sites->isEmpty()
                ? 'A site must be connected before this workspace can be opened.'
                : 'Explicit site context is required when the tenant has multiple sites.');
        }

        $site = $sites->firstOrFail();
        $request->session()->put(self::SITE_SESSION_KEY, (int) $site->getKey());

        return $site;
    }

    private function target(Request $request, string $tenant): string
    {
        $target = (string) ($request->route()->defaults['workspace_target'] ?? '');
        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            throw new RuntimeException('Canonical workspace redirect target is invalid.');
        }

        return "/tenants/{$tenant}{$target}";
    }

    private function authorizeRoute(Request $request): void
    {
        $raw = (string) ($request->route()->defaults['workspace_permissions'] ?? 'tenant.view');
        $permissions = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($permissions as $permission) {
            $this->authorizer->authorize($permission);
        }
    }
}
