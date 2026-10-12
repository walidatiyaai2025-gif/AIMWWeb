<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SiteSettingsSaveProfileController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-5C15471E4B';

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
            'status',
            'connection_status',
            'health_state',
        ])) {
            throw ValidationException::withMessages([
                'request' => 'Tenant, site ownership, actor identity, and operational state are server-derived.',
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $name = trim((string) $validated['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Site name is required.']);
        }

        $url = $this->normalizeSiteUrl((string) $validated['url']);

        DB::transaction(function () use ($activeTenant, $site, $name, $url): void {
            $model = Site::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $activeTenant->getKey())
                ->whereKey($site)
                ->lockForUpdate()
                ->firstOrFail();

            if (hash_equals((string) $model->name, $name) && hash_equals((string) $model->url, $url)) {
                return;
            }

            $model->name = $name;
            $model->url = $url;
            $model->save();
        }, 3);

        $authoritative = Site::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $activeTenant->getKey())
            ->whereKey($site)
            ->firstOrFail(['id', 'name', 'url']);

        abort_unless(
            hash_equals($name, (string) $authoritative->name)
                && hash_equals($url, (string) $authoritative->url),
            409,
            'Site profile could not be verified after persistence.',
        );

        return redirect()
            ->route('canonical.site.settings', ['tenant' => $activeTenant->slug, 'site' => $site])
            ->with('status', 'Site profile updated.');
    }

    private function normalizeSiteUrl(string $value): string
    {
        $value = trim($value);
        $parts = parse_url($value);

        if ($parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw ValidationException::withMessages([
                'url' => 'Enter a valid site URL starting with http or https.',
            ]);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) === false && ! str_contains($host, '.')) {
            throw ValidationException::withMessages([
                'url' => 'Enter a valid site URL starting with http or https.',
            ]);
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return "{$scheme}://{$host}{$port}/";
    }
}
