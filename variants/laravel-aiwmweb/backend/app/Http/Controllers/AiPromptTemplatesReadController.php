<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AiPromptTemplatesReadController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant): View
    {
        $this->authorizer->authorize('settings.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $locale = $request->query('lang');
        if (in_array($locale, ['ar', 'en'], true)) {
            $request->session()->put('aiwm.prompt.locale', $locale);
        } else {
            $locale = $request->session()->get('aiwm.prompt.locale', app()->getLocale());
        }
        $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'en';

        $templates = AiPromptTemplate::query()
            ->with('revisions')
            ->orderBy('domain')
            ->orderBy('stable_key')
            ->get();

        return view('ai.prompt-templates', [
            'tenant' => $this->context->tenant(),
            'templates' => $templates,
            'locale' => $locale,
        ]);
    }
}
