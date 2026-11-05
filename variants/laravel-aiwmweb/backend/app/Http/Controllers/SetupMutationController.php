<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupMutationService;
use App\Services\DatabaseSetupPageService;
use App\Services\SetupOwnerProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class SetupMutationController extends Controller
{
    public function __construct(
        private readonly DatabaseSetupMutationService $mutationService,
        private readonly DatabaseSetupPageService $pageService,
        private readonly SetupOwnerProof $ownerProof,
    ) {}

    public function __invoke(Request $request): RedirectResponse|Response
    {
        if ($this->pageService->status()['complete']) {
            return redirect('/');
        }

        if (! $request->isSecure() && ! app()->environment('testing', 'local')) {
            abort(403, 'Owner setup requires HTTPS.');
        }

        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
            'owner_activation_secret' => ['required', 'string', 'size:64'],
        ]);

        if (! $this->ownerProof->authorized($request)) {
            abort(403, 'Hosting owner activation is required.');
        }

        try {
            $this->ownerProof->withLock(function () use ($request, $validated): void {
                if (! $this->ownerProof->authorized($request) || $this->pageService->status()['complete']) {
                    throw new \RuntimeException('Owner challenge is invalid or setup is already complete.');
                }

                $this->mutationService->apply([
                    'tenant_name' => $validated['tenant_name'],
                    'admin_name' => $validated['admin_name'],
                    'admin_email' => $validated['admin_email'],
                    'admin_password' => $validated['admin_password'],
                ]);
                $this->ownerProof->consume();
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->pageService->render(DatabaseSetupPageService::FAILURE_MESSAGE, 400);
        }

        $request->session()->regenerate();

        return redirect('/');
    }
}
