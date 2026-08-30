<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupMutationService;
use App\Services\DatabaseSetupPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SetupMutationController extends Controller
{
    public function __construct(
        private readonly DatabaseSetupMutationService $mutationService,
        private readonly DatabaseSetupPageService $pageService,
    ) {}

    public function __invoke(Request $request): RedirectResponse|Response
    {
        if ($this->pageService->status()['complete']) {
            return redirect('/');
        }

        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        try {
            $this->mutationService->apply($validated);
        } catch (Throwable $exception) {
            report($exception);

            return $this->pageService->render(DatabaseSetupPageService::FAILURE_MESSAGE, 400);
        }

        return redirect('/');
    }
}
