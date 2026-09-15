<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

final class PublicWelcomeReadController extends Controller
{
    public function __invoke(): View
    {
        return view('ai.welcome');
    }
}
