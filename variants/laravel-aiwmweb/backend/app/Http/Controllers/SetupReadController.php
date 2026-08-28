<?php

namespace App\Http\Controllers;

use App\Services\DatabaseSetupReadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class SetupReadController extends Controller
{
    public function __invoke(DatabaseSetupReadService $setup): View|RedirectResponse
    {
        $status = $setup->status();

        if ($status['complete']) {
            return redirect('/');
        }

        return view('setup', ['status' => $status]);
    }
}
