<?php

namespace App\Http\Controllers;

use App\Backup\TenantBackupCreateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BackupCreateController extends Controller
{
    public function __construct(private readonly TenantBackupCreateService $service) {}

    public function __invoke(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'recovery_secret' => ['nullable', 'string', 'min:16', 'max:1024'],
            'recovery_secret_confirmation' => ['nullable', 'string', 'max:1024'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
            'path' => ['prohibited'],
            'sha256' => ['prohibited'],
        ]);

        $secret = isset($data['recovery_secret']) ? (string) $data['recovery_secret'] : null;
        $confirmation = isset($data['recovery_secret_confirmation']) ? (string) $data['recovery_secret_confirmation'] : null;
        if (($secret !== null || $confirmation !== null) && ($secret === null || $confirmation === null || ! hash_equals($secret, $confirmation))) {
            throw ValidationException::withMessages([
                'recovery_secret_confirmation' => 'Recovery secret and confirmation must both be present and match.',
            ]);
        }

        $backup = $this->service->create(
            (int) $request->user()->getAuthIdentifier(),
            isset($data['note']) ? (string) $data['note'] : null,
            $secret,
        );

        return response()->json(['data' => $backup], 201);
    }
}
