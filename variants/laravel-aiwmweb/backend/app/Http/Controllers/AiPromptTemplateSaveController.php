<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\PromptRegistryService;
use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AiPromptTemplateSaveController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
        private readonly PromptRegistryService $registry,
    ) {}

    public function __invoke(Request $request, string $tenant, string $template): RedirectResponse
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $prompt = AiPromptTemplate::query()
            ->where('stable_key', $template)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'system_template' => ['nullable', 'string', 'max:20000'],
            'user_template' => ['required', 'string', 'max:20000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $saved = $this->registry->save($prompt, [
            'domain' => $prompt->domain,
            'title' => $validated['title'],
            'system_template' => $validated['system_template'] ?? null,
            'user_template' => $validated['user_template'],
            'variables' => $prompt->variables ?? [],
            'output_schema' => $prompt->output_schema,
            'enabled' => (bool) $validated['enabled'],
        ]);

        return redirect()
            ->route('tenant.settings.ai-prompts', ['tenant' => $tenant])
            ->with('status', "Saved {$saved->stable_key} as revision r{$saved->current_version}.");
    }
}
