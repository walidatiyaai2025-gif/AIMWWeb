<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Execution;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SiteSettingsDeleteController extends Controller
{
    public const CANONICAL_OPERATION_ID = 'AIMW-BILL-D7D075EF3C';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request, string $tenant, int|string $site): RedirectResponse
    {
        $this->authorizer->authorize('sites.manage');

        $activeTenant = $this->tenantContext->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);
        abort_unless(is_int($site) || ctype_digit($site), 404);

        $siteId = (int) $site;
        abort_if($siteId < 1, 404);

        $model = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($siteId)
            ->firstOrFail();

        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:255'],
        ]);

        if (! hash_equals((string) $model->name, (string) $data['confirmation'])) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type the exact site name to confirm deletion.',
            ]);
        }

        $actorUserId = (int) $request->user()->getAuthIdentifier();
        abort_if($actorUserId < 1, 403);

        DB::transaction(function () use ($activeTenant, $siteId): void {
            $lockedSite = Site::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->whereKey($siteId)
                ->lockForUpdate()
                ->firstOrFail();

            $activeExecutionExists = Execution::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->where('site_id', $siteId)
                ->whereIn('status', ['queued', 'running'])
                ->exists();
            abort_if($activeExecutionExists, 409, 'Active execution prevents deletion.');

            $lockedSite->delete();
        });

        $stillExists = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($siteId)
            ->exists();
        abort_if($stillExists, 409, 'Site deletion could not be verified.');

        if ((int) $request->session()->get('canonical_site_id') === $siteId) {
            $request->session()->forget('canonical_site_id');
        }

        return redirect('/sites')->with('status', 'Site deleted.');
    }
}
