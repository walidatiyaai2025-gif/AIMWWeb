<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\AIProviderSettingsAdministrationService;
use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

final class AiProviderSettingsReadController extends Controller
{
    public function __construct(
        private readonly AIProviderSettingsAdministrationService $settings,
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function __invoke(string $tenant): View
    {
        abort_unless($this->context->tenant()->slug === $tenant, 404);
        $this->authorizer->authorize('settings.manage');

        $settings = $this->settings->getAiSettingsAsync();

        return view('ai.provider-settings', [
            'tenant' => $this->context->tenant(),
            'providers' => $settings['providers'] ?? [],
        ]);
    }
}
