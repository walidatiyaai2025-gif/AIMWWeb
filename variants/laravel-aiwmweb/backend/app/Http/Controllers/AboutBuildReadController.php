<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Platform\BuildInformationReadService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;

final class AboutBuildReadController extends Controller
{
    public function __invoke(
        string $tenant,
        BuildInformationReadService $buildInformation,
        TenantAuthorizer $authorizer,
        TenantContext $context,
    ): View {
        $authorizer->authorize('tenant.view');
        abort_unless($context->tenant()->slug === $tenant, 404);

        return view('platform.about-build', [
            'build' => $buildInformation->snapshot(),
            'currentRelease' => null,
            'releases' => [],
            'runtime' => 'PHP '.PHP_VERSION,
            'framework' => 'Laravel '.Application::VERSION,
            'operatingSystem' => PHP_OS_FAMILY.' '.php_uname('m'),
        ]);
    }
}
