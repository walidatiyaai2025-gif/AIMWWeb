<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

final class PublicWelcomeReadController extends Controller
{
    public function __invoke(Request $request): View
    {
        $locale = $request->query('lang');
        if (in_array($locale, ['en', 'ar'], true)) {
            $request->session()->put('public_welcome_locale', $locale);
        } else {
            $locale = $request->session()->get('public_welcome_locale', 'en');
        }
        $locale = in_array($locale, ['en', 'ar'], true) ? $locale : 'en';

        return view('ai.welcome', ['welcomeLocale' => $locale]);
    }
}
