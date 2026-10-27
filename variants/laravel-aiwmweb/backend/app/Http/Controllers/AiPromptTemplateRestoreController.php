<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\PromptRegistryService;
use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class AiPromptTemplateRestoreController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
        private readonly PromptRegistryService $registry,
    ) {}

    public function __invoke(string $tenant, string $template, int $version): RedirectResponse
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $prompt = AiPromptTemplate::query()
            ->where('stable_key', $template)
            ->firstOrFail();

        abort_if($version === (int) $prompt->current_version, 409, 'The current prompt revision cannot be restored.');

        $restored = $this->registry->restore($prompt, $version);

        return redirect()
            ->route('tenant.settings.ai-prompts', ['tenant' => $tenant])
            ->with('status', "Restored {$restored->stable_key} as revision r{$restored->current_version}.");
    }
}
