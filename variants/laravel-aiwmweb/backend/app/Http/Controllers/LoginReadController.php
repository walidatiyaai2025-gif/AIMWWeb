<?php

namespace App\Http\Controllers;

use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class LoginReadController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|View
    {
        $returnUrl = $this->safeReturnUrl($request->query('returnUrl'));

        if ($request->user() !== null) {
            return redirect()->to($this->destination($request, $returnUrl));
        }

        $error = $request->query('error');
        $error = is_string($error) ? mb_substr(trim($error), 0, 512) : '';

        return view('auth.login', [
            'returnUrl' => $returnUrl,
            'error' => $error,
        ]);
    }


    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'returnUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $identifier = trim((string) $data['email']);
        $credentials = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? ['email' => $identifier, 'password' => $data['password']]
            : ['normalized_username' => strtoupper($identifier), 'password' => $data['password']];

        if (! Auth::attempt($credentials)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Invalid email/username or password.'])
                ->withInput($request->only('email', 'returnUrl'));
        }

        $request->session()->regenerate();

        return redirect()->to($this->destination($request, $this->safeReturnUrl($data['returnUrl'] ?? null)));
    }

    private function destination(Request $request, string $returnUrl): string
    {
        // Explicit internal destinations still pass through tenant-specific authorization.
        if ($returnUrl !== '/' && $returnUrl !== '/login' && ! str_starts_with($returnUrl, '/api/')) {
            return $returnUrl;
        }

        $membership = TenantMembership::withoutGlobalScopes()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->with('tenant:id,slug')
            ->orderBy('id')
            ->get()
            ->first(fn (TenantMembership $membership): bool => $membership->tenant !== null);

        return $membership?->tenant
            ? '/tenants/'.rawurlencode((string) $membership->tenant->slug)
            : '/';
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
