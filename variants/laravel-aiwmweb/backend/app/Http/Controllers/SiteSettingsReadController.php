<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class SiteSettingsReadController
{
    public function __invoke(
        Request $request,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): View {
        $authorizer->authorize('tenant.view');
        $authorizer->authorize('sites.manage');

        $site = Site::query()->findOrFail((int) $request->route('site'));

        return view('platform.site-settings', [
            'tenant' => $context->tenant(),
            'site' => $site,
        ]);
    }
}
