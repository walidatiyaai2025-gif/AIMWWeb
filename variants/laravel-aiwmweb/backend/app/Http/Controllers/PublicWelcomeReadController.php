<?php

namespace App\Http\Controllers;

use App\Models\BillingPlan;
use App\Models\TenantMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

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

        try {
            $plans = BillingPlan::query()
                ->where('enabled', true)
                ->whereNull('retired_at')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get([
                    'id',
                    'code',
                    'name',
                    'localized_name',
                    'description',
                    'localized_description',
                    'price_minor',
                    'currency',
                    'billing_interval',
                    'trial_period_days',
                    'display_order',
                ]);
        } catch (Throwable $exception) {
            report($exception);
            $plans = collect();
        }

        return view('ai.welcome', [
            'welcomeLocale' => $locale,
            'plans' => $plans,
            'isAuthenticated' => $request->user() !== null,
        ]);
    }

    public function billing(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $memberships = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->with('tenant:id,slug')
            ->orderBy('tenant_id')
            ->limit(2)
            ->get();

        abort_if($memberships->isEmpty(), 409, 'An active tenant membership is required to open account billing.');
        abort_if($memberships->count() !== 1, 409, 'Explicit tenant selection is required to open account billing.');

        $membership = $memberships->firstOrFail();
        abort_unless($membership->tenant !== null, 404);

        return redirect()->to(route(
            'canonical.workspace.account-billing',
            ['tenant' => $membership->tenant->slug],
            false,
        ));
    }
}
