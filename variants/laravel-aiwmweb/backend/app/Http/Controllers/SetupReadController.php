<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupPageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

final class SetupReadController extends Controller
{
    public function __invoke(DatabaseSetupPageService $page): RedirectResponse|Response
    {
        $status = $page->status();

        if ($status['complete']) {
            return redirect('/');
        }

        return $page->render(setupStatus: $status);
    }
}
