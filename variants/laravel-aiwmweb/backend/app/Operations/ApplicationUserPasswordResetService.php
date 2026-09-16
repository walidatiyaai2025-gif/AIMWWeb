<?php

namespace App\Operations;

use App\Models\AuditEvent;
use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ApplicationUserPasswordResetService
{
    public function __construct(private readonly Redactor $redactor) {}

    public function reset(int $membershipId, string $password, string $confirmationIdentity, int $actorUserId): array
    {
        $result = DB::transaction(function () use ($membershipId, $password, $confirmationIdentity, $actorUserId): array {
            $membership = TenantMembership::query()
                ->with('user')
                ->lockForUpdate()
                ->findOrFail($membershipId);
            $user = $membership->user;
            if ($user === null) {
                throw (new ModelNotFoundException)->setModel('user');
            }

            if (! hash_equals(strtolower($user->email), strtolower(trim($confirmationIdentity)))) {
                throw ValidationException::withMessages([
                    'confirmation_identity' => 'Type the target member email exactly to confirm this password reset.',
                ]);
            }

            $user->password = $password;
            $user->save();

            $revokedSessions = DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->delete();

            AuditEvent::query()->create([
                'actor_user_id' => $actorUserId,
                'event' => 'member.password_reset',
                'subject_type' => 'tenant_membership',
                'subject_id' => (string) $membership->getKey(),
                'metadata' => $this->redactor->redact([
                    'target_user_id' => (int) $user->getKey(),
                    'revoked_sessions' => $revokedSessions,
                ]),
                'occurred_at' => now(),
            ]);

            return [
                'membership_id' => (int) $membership->getKey(),
                'user_id' => (int) $user->getKey(),
                'revoked_sessions' => $revokedSessions,
            ];
        });

        $membership = TenantMembership::query()
            ->with('user')
            ->findOrFail($result['membership_id']);
        $user = $membership->user;
        if ($user === null || (int) $user->getKey() !== $result['user_id'] || ! Hash::check($password, $user->password)) {
            throw new RuntimeException('Password reset persistence verification failed.');
        }

        return [
            'membership_id' => (int) $membership->getKey(),
            'user' => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'password_changed' => true,
            'revoked_sessions' => $result['revoked_sessions'],
        ];
    }
}
