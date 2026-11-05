<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupPageService;
use App\Services\SetupOwnerProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class SetupReadController extends Controller
{
    public function __invoke(DatabaseSetupPageService $page, SetupOwnerProof $proof): RedirectResponse|Response
    {
        $status = $page->status();

        if ($status['complete']) {
            return redirect('/');
        }

        if (! request()->isSecure() && ! app()->environment('testing', 'local')) {
            abort(403, 'Owner setup requires HTTPS.');
        }

        $proof->ensureIssued();

        return $page->render(setupStatus: $status);
    }
}
