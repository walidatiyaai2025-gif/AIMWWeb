<?php

namespace App\Http\Controllers;

use App\Auth\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class RegisterController extends Controller
{
    public const OPERATION_ID = 'AIMW-BILL-34B7686F5E';

    /** @var list<string> */
    private const FORBIDDEN_OWNERSHIP_FIELDS = [
        'tenant',
        'tenant_id',
        'user_id',
        'membership_id',
        'role_id',
        'plan_id',
        'plan_code',
        'billing_plan_id',
        'trial_days',
    ];

    public function show(Request $request): RedirectResponse|View
    {
        if ($request->user() !== null) {
            return redirect('/');
        }

        return view('auth.register', [
            'error' => '',
            'accountCreated' => false,
        ]);
    }

    public function store(Request $request, RegistrationService $registration): RedirectResponse|Response
    {
        if ($request->user() !== null) {
            abort(403, 'Authenticated users cannot use public registration.');
        }

        $unexpected = array_values(array_intersect(self::FORBIDDEN_OWNERSHIP_FIELDS, $request->keys()));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'request' => 'Registration does not accept caller-supplied tenant, user, role, plan, or trial ownership identifiers.',
            ]);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'username' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'regex:/[A-Z]/',
                    'regex:/[a-z]/',
                    'regex:/[0-9]/',
                    'confirmed',
                ],
            ],
            [
                'username.min' => 'Username must be between 3 and 64 characters.',
                'username.max' => 'Username must be between 3 and 64 characters.',
                'username.regex' => 'Username can contain letters, numbers, dots, underscores, and hyphens only.',
                'password.min' => 'Password must contain at least 8 characters.',
                'password.regex' => 'Password must contain uppercase, lowercase, and numeric characters.',
                'password.confirmed' => 'Password confirmation does not match.',
            ],
        );

        $data = $validator->validate();
        $identity = $registration->register($data['username'], $data['password']);

        try {
            $registration->startFreeTrial($identity['tenant'], $identity['membership']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->view('auth.register', [
                'error' => 'The free trial plan is currently unavailable. The account was created; contact an administrator to assign a subscription.',
                'accountCreated' => true,
                'registeredUserName' => $identity['user']->username,
            ], 503);
        }

        return redirect('/login?registered=true');
    }
}
