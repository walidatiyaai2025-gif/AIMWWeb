<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupMutationService;
use App\Services\DatabaseSetupReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SetupMutationController extends Controller
{
    public function __construct(
        private readonly DatabaseSetupMutationService $mutationService,
        private readonly DatabaseSetupReadService $readService,
    ) {}

    public function __invoke(Request $request): RedirectResponse|Response
    {
        if ($this->readService->status()['complete']) {
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

            return response()->view('setup', [
                'status' => $this->readService->status(),
                'error' => 'Setup could not be completed safely. Verify the configured database and existing installation state, then try again.',
            ], 400);
        }

        return redirect('/');
    }
}
