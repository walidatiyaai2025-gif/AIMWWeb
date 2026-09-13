<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SiteSettingsReadController extends Controller
{
    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function __invoke(Request $request, string $tenant, int $site): View
    {
        $this->authorizer->authorize('tenant.view');
        $this->authorizer->authorize('sites.view');

        $model = Site::query()->findOrFail($site);

        return view('sites.settings', [
            'site' => $model,
            'tenant' => $tenant,
        ]);
    }
}
