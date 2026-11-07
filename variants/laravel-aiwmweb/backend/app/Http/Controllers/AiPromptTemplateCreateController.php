<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\PromptRegistryService;
use App\Authorization\TenantAuthorizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AiPromptTemplateCreateController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
        private readonly PromptRegistryService $registry,
    ) {}

    public function __invoke(Request $request, string $tenant): RedirectResponse
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $validated = $request->validate([
            'stable_key' => [
                'required', 'string', 'max:80',
                'regex:'.PromptRegistryService::KEY_PATTERN,
                Rule::unique('ai_prompt_templates', 'stable_key')
                    ->where('tenant_id', $this->context->tenant()->id),
            ],
            'title' => ['required', 'string', 'max:120'],
            'system_template' => ['nullable', 'string', 'max:20000'],
            'user_template' => ['required', 'string', 'max:20000'],
            'enabled' => ['required', 'boolean'],
        ]);

        $saved = $this->registry->save(null, [
            ...$validated,
            'domain' => 'general',
            'variables' => [],
            'output_schema' => null,
            'enabled' => (bool) $validated['enabled'],
        ], 'created');

        return redirect()
            ->route('tenant.settings.ai-prompts', ['tenant' => $tenant])
            ->with('status', "Created {$saved->stable_key} as revision r{$saved->current_version}.");
    }
}
