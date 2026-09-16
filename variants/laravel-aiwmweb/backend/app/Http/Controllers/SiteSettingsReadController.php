<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SiteSettingsReadController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request, string $tenant, int $site): View
    {
        $activeTenant = $this->tenantContext->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        $this->authorizer->authorize('tenant.view');
        $this->authorizer->authorize('sites.view');

        $model = Site::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail();

        $canManageCredential = $this->tenantContext->membership()->hasPermission('sites.manage');
        $credential = $canManageCredential
            ? SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $model->getKey())
                ->first(['id', 'tenant_id', 'site_id', 'username', 'created_at', 'updated_at'])
            : null;

        return view('sites.settings', [
            'site' => $model,
            'tenant' => $activeTenant->slug,
            'credential' => $credential,
            'canManageCredential' => $canManageCredential,
        ]);
    }
}
