<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Operations\ApplicationUserPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class ApplicationUserPasswordResetController extends Controller
{
    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ApplicationUserPasswordResetService $passwordReset,
    ) {}

    public function __invoke(Request $request, string $tenant, int $membership): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'password_confirmation' => ['required', 'string'],
            'confirmation_identity' => ['required', 'string', 'max:255'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'membership_id' => ['prohibited'],
        ]);

        $result = $this->passwordReset->reset(
            $membership,
            $data['password'],
            $data['confirmation_identity'],
            (int) $request->user()->getAuthIdentifier(),
        );

        return response()->json($result);
    }
}
