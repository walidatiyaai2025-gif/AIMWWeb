<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LoginReadController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|View
    {
        $returnUrl = $this->safeReturnUrl($request->query('returnUrl'));

        if ($request->user() !== null) {
            return redirect()->to($returnUrl);
        }

        $error = $request->query('error');
        $error = is_string($error) ? mb_substr(trim($error), 0, 512) : '';

        return view('auth.login', [
            'returnUrl' => $returnUrl,
            'error' => $error,
        ]);
    }

    private function safeReturnUrl(mixed $value): string
    {
        if (! is_string($value)) {
            return '/';
        }

        $candidate = trim($value);
        if ($candidate === '' || ! $this->isSafeLocalPath($candidate)) {
            return '/';
        }

        return $candidate;
    }

    private function isSafeLocalPath(string $path): bool
    {
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        $decoded = rawurldecode($path);

        return str_starts_with($decoded, '/')
            && ! str_starts_with($decoded, '//')
            && ! str_contains($decoded, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $decoded) !== 1;
    }
}
