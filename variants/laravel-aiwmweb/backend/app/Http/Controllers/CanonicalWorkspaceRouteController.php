<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class CanonicalWorkspaceRouteController extends Controller
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function show(Request $request, string $tenant): View
    {
        $this->authorizeRoute($request);

        return view('app');
    }

    public function redirect(Request $request, string $tenant): RedirectResponse
    {
        $this->authorizeRoute($request);
        $target = (string) ($request->route()->getDefaults()['workspace_target'] ?? '');

        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            throw new RuntimeException('Canonical workspace redirect target is invalid.');
        }

        return redirect("/tenants/{$tenant}{$target}");
    }

    private function authorizeRoute(Request $request): void
    {
        $raw = (string) ($request->route()->getDefaults()['workspace_permissions'] ?? 'tenant.view');
        $permissions = array_values(array_filter(array_map('trim', explode(',', $raw))));

        foreach ($permissions as $permission) {
            $this->authorizer->authorize($permission);
        }
    }
}
