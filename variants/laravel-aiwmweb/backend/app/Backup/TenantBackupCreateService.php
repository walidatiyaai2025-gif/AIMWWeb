<?php

namespace App\Backup;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class TenantBackupCreateService
{
    public const OPERATION_ID = 'AIMW-BILL-DDA412F087';

    public function __construct(private readonly TenantContext $context) {}

    public function create(int $actorUserId, ?string $note, ?string $recoverySecret): array
    {
        $tenantId = $this->context->id();
        $recoverySecret = $recoverySecret !== null ? trim($recoverySecret) : null;
        if ($recoverySecret === '') {
            $recoverySecret = null;
        }
        if ($recoverySecret !== null && (mb_strlen($recoverySecret) < 16 || mb_strlen($recoverySecret) > 1024)) {
            throw new \InvalidArgumentException('Recovery secret must contain 16 to 1024 characters.');
        }

        $dataKey = $recoverySecret !== null ? random_bytes(32) : null;
        $wrappedKey = $dataKey !== null ? $this->wrapKey($dataKey, $recoverySecret) : null;
        if ($wrappedKey !== null && ! hash_equals($dataKey, $this->unwrapKey($wrappedKey, $recoverySecret))) {
            throw new RuntimeException('Wrapped recovery key verification failed.');
        }

        $tables = [];
        foreach (Schema::getTableListing() as $table) {
            if ($table === 'backup_archives' || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $rows = DB::table($table)->where('tenant_id', $tenantId)->get()->map(function ($row) use ($dataKey): array {
                $values = (array) $row;
                foreach ($values as $column => $value) {
                    if (! $this->isProtectedColumn((string) $column) || $value === null) {
                        continue;
                    }
                    $values[$column] = $dataKey === null
                        ? ['redacted' => true]
                        : ['encrypted' => $this->encryptProtected((string) $value, $dataKey)];
                }

                return $values;
            })->values()->all();

            $tables[$table] = $rows;
        }

        $createdAt = now()->utc();
        $payload = [
            'schema_version' => 1,
            'operation_id' => self::OPERATION_ID,
            'tenant_id' => $tenantId,
            'created_at' => $createdAt->toIso8601String(),
            'created_by' => $actorUserId,
            'note' => $note !== null ? trim($note) : null,
            'protected_secret_recovery' => $wrappedKey !== null,
            'wrapped_data_key' => $wrappedKey,
            'safe_configuration' => [
                'app_name' => (string) config('app.name'),
                'app_locale' => (string) config('app.locale'),
                'queue_default' => (string) config('queue.default'),
                'cache_default' => (string) config('cache.default'),
            ],
            'tables' => $tables,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($json, 9);
        if ($compressed === false) {
            throw new RuntimeException('Backup compression failed.');
        }

        $filename = sprintf(
            'backups/tenant-%d/%s-%s.json.gz',
            $tenantId,
            $createdAt->format('Ymd-His'),
            bin2hex(random_bytes(6)),
        );
        Storage::disk('local')->put($filename, $compressed);
        $stored = Storage::disk('local')->get($filename);
        $sha256 = hash('sha256', $stored);
        $decoded = gzdecode($stored);
        $verifiedPayload = $decoded !== false ? json_decode($decoded, true) : null;
        if (! hash_equals(hash('sha256', $compressed), $sha256)
            || ! is_array($verifiedPayload)
            || (int) ($verifiedPayload['tenant_id'] ?? 0) !== $tenantId
            || ($verifiedPayload['operation_id'] ?? null) !== self::OPERATION_ID) {
            Storage::disk('local')->delete($filename);
            throw new RuntimeException('Stored backup verification failed.');
        }

        try {
            $archiveId = DB::table('backup_archives')->insertGetId([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'operation_id' => self::OPERATION_ID,
                'path' => $filename,
                'sha256' => $sha256,
                'size_bytes' => strlen($stored),
                'note' => $payload['note'],
                'protected_secret_recovery' => $wrappedKey !== null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($filename);
            throw $exception;
        }

        return [
            'id' => (int) $archiveId,
            'operation_id' => self::OPERATION_ID,
            'path' => $filename,
            'sha256' => $sha256,
            'size_bytes' => strlen($stored),
            'note' => $payload['note'],
            'protected_secret_recovery' => $wrappedKey !== null,
            'verified' => true,
            'created_at' => $createdAt->toIso8601String(),
        ];
    }

    private function isProtectedColumn(string $column): bool
    {
        return (bool) preg_match('/(?:password|secret|token|api[_-]?key|credential|private[_-]?key|encrypted)/i', $column);
    }

    private function encryptProtected(string $value, string $key): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Protected backup field encryption failed.');
        }

        return [
            'alg' => 'AES-256-GCM',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    private function wrapKey(string $dataKey, string $secret): array
    {
        $salt = random_bytes(16);
        $kek = hash_pbkdf2('sha256', $secret, $salt, 200000, 32, true);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($dataKey, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Recovery key wrapping failed.');
        }

        return [
            'version' => 1,
            'kdf' => 'PBKDF2-SHA256',
            'iterations' => 200000,
            'salt' => base64_encode($salt),
            'alg' => 'AES-256-GCM',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    private function unwrapKey(array $wrapped, string $secret): string
    {
        $salt = base64_decode((string) $wrapped['salt'], true);
        $iv = base64_decode((string) $wrapped['iv'], true);
        $tag = base64_decode((string) $wrapped['tag'], true);
        $ciphertext = base64_decode((string) $wrapped['ciphertext'], true);
        if ($salt === false || $iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Wrapped recovery key is malformed.');
        }

        $kek = hash_pbkdf2('sha256', $secret, $salt, (int) $wrapped['iterations'], 32, true);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Wrapped recovery key verification failed.');
        }

        return $plain;
    }
}
