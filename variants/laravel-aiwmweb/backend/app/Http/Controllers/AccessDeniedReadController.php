<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

final class AccessDeniedReadController extends Controller
{
    public function __invoke(): View
    {
        return view('platform.access-denied');
    }
}
