<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SiteSettingsToggleDisabledController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-84B63E3F42';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant, int $site): RedirectResponse
    {
        $this->authorizer->authorize('tenant.view');
        $this->authorizer->authorize('sites.view');
        $this->authorizer->authorize('sites.manage');

        $activeTenant = $this->context->tenant();
        abort_unless(hash_equals($activeTenant->slug, $tenant), 404);

        if ($request->hasAny([
            'tenant_id',
            'site_id',
            'actor_user_id',
            'connection_status',
            'status',
            'secret',
        ])) {
            throw ValidationException::withMessages([
                'request' => 'Tenant, site ownership, actor identity, and persisted status are server-derived.',
            ]);
        }

        $validated = $request->validate([
            'disabled' => ['required', 'boolean'],
        ]);

        $disabled = (bool) $validated['disabled'];
        $targetConnectionStatus = $disabled ? 'disabled' : 'unknown';

        DB::transaction(function () use ($activeTenant, $site, $targetConnectionStatus): void {
            $model = Site::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->whereKey($site)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $model->connection_status === $targetConnectionStatus) {
                return;
            }

            $model->connection_status = $targetConnectionStatus;
            $model->save();
        }, 3);

        $authoritative = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail(['id', 'connection_status']);

        abort_unless(
            hash_equals($targetConnectionStatus, (string) $authoritative->connection_status),
            409,
            'Site operational state could not be verified after persistence.',
        );

        $message = $disabled
            ? 'Site disabled.'
            : 'Site enabled and reset for connection testing.';

        return redirect()
            ->route('canonical.site.settings', ['tenant' => $activeTenant->slug, 'site' => $site])
            ->with('status', $message);
    }
}
