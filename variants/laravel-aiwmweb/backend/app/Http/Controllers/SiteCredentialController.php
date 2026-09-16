<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SiteCredentialController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function destroy(string $tenant, int $site): RedirectResponse
    {
        $activeTenant = $this->tenantContext->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        $this->authorizer->authorize('sites.manage');

        $siteModel = Site::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail();

        DB::transaction(function () use ($activeTenant, $siteModel): void {
            $credential = SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteModel->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $credential->delete();

            $stillExists = SiteCredential::withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteModel->getKey())
                ->exists();

            if ($stillExists) {
                throw new RuntimeException('Credential removal could not be verified.');
            }
        });

        $authoritativeCredential = SiteCredential::withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->where('site_id', $siteModel->getKey())
            ->first();

        if ($authoritativeCredential !== null) {
            throw new RuntimeException('Credential removal could not be reconciled.');
        }

        return redirect()
            ->route('canonical.site.settings', ['tenant' => $activeTenant->slug, 'site' => $siteModel->getKey()])
            ->with('status', 'Encrypted credential removed.');
    }
}
