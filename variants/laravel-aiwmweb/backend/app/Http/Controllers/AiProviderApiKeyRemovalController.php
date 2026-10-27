<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\AIProviderSettingsAdministrationService;
use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AiProviderApiKeyRemovalController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-6701FB22AE';

    public function __construct(
        private readonly AIProviderSettingsAdministrationService $settings,
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request, string $tenant, string $provider): RedirectResponse
    {
        abort_unless($this->context->tenant()->slug === $tenant, 404);
        $this->authorizer->authorize('settings.manage');

        if ((string) $request->input('confirmation') !== 'REMOVE') {
            throw ValidationException::withMessages([
                'confirmation' => 'Type REMOVE exactly to confirm API key removal.',
            ]);
        }

        $this->settings->clearAiProviderApiKeyAsync($provider);

        return redirect()
            ->route('tenant.settings.ai-providers', ['tenant' => $tenant])
            ->with('status', 'Stored API key removed. Provider readiness was reset and the provider state was reloaded.');
    }
}
