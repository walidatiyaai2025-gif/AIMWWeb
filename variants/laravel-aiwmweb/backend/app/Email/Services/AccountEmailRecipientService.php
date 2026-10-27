<?php

namespace App\Email\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\AccountEmailRecipient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountEmailRecipientService
{
    public const UPDATE_OPERATION_ID = 'AIMW-EMAI-10F3C44369';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
    ) {}

    /** @return Collection<int, AccountEmailRecipient> */
    public function listAsync(): Collection
    {
        $this->authorizer->authorize('tenant.manage');

        return AccountEmailRecipient::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function updateAsync(
        int $recipientId,
        string $emailAddress,
        ?string $displayName,
        bool $enabled,
    ): AccountEmailRecipient {
        $this->authorizer->authorize('tenant.manage');
        $email = $this->normalizeEmail($emailAddress);
        $normalized = mb_strtoupper($email, 'UTF-8');
        $display = $this->normalizeDisplayName($displayName);

        return DB::transaction(function () use (
            $recipientId,
            $email,
            $normalized,
            $display,
            $enabled,
        ): AccountEmailRecipient {
            $recipient = AccountEmailRecipient::query()
                ->whereKey($recipientId)
                ->lockForUpdate()
                ->firstOrFail();

            $duplicate = AccountEmailRecipient::query()
                ->where('normalized_email_address', $normalized)
                ->where('id', '<>', $recipientId)
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'email_address' => 'This email address is already configured for the active account.',
                ]);
            }

            $recipient->update([
                'email_address' => $email,
                'normalized_email_address' => $normalized,
                'display_name' => $display,
                'is_enabled' => $enabled,
            ]);

            return $recipient->refresh();
        }, 3);
    }

    /** @return array{id:int,email_address:string,display_name:?string,is_enabled:bool} */
    public function serialize(AccountEmailRecipient $recipient): array
    {
        return [
            'id' => (int) $recipient->getKey(),
            'email_address' => (string) $recipient->email_address,
            'display_name' => $recipient->display_name === null ? null : (string) $recipient->display_name,
            'is_enabled' => (bool) $recipient->is_enabled,
        ];
    }

    private function normalizeEmail(string $emailAddress): string
    {
        $clean = trim($emailAddress);
        if ($clean === '') {
            throw ValidationException::withMessages(['email_address' => 'Email address is required.']);
        }
        if (mb_strlen($clean) > 320) {
            throw ValidationException::withMessages(['email_address' => 'Email address is too long.']);
        }
        if (filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email_address' => 'Enter a valid email address.']);
        }

        return $clean;
    }

    private function normalizeDisplayName(?string $displayName): ?string
    {
        $clean = trim((string) $displayName);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > 120) {
            throw ValidationException::withMessages(['display_name' => 'Display name is too long.']);
        }

        return $clean;
    }
}
