<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

final class ConfigurationValidationController extends Controller
{
    public const COPY_REPORT_OPERATION_ID = 'AIMW-BILL-39BB044AF2';

    public const RUN_VALIDATION_OPERATION_ID = 'AIMW-BILL-EE0BEAAC55';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function show(string $tenant): JsonResponse
    {
        return $this->validationResponse(self::COPY_REPORT_OPERATION_ID);
    }

    public function run(string $tenant): JsonResponse
    {
        return $this->validationResponse(self::RUN_VALIDATION_OPERATION_ID);
    }

    private function validationResponse(string $operationId): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');

        $items = [
            $this->item(
                'environment',
                'Runtime environment',
                app()->environment('production') ? 'valid' : 'warning',
                app()->environment('production') ? 'Production runtime' : 'Non-production runtime',
                app()->environment('production')
                    ? 'The application is running in the production environment.'
                    : 'The application is not running in the production environment.',
            ),
            $this->item(
                'app-key',
                'Application encryption key',
                filled(config('app.key')) ? 'valid' : 'error',
                filled(config('app.key')) ? 'Configured' : 'Missing',
                filled(config('app.key'))
                    ? 'The application encryption key is configured.'
                    : 'The application encryption key is not configured.',
            ),
            $this->storageItem('local-data', 'Managed application-data storage', storage_path('app')),
            $this->storageItem('logs', 'Managed log storage', storage_path('logs')),
            $this->backupStorageItem(),
        ];

        $critical = count(array_filter($items, fn (array $item) => $item['status'] === 'error'));
        $warnings = count(array_filter($items, fn (array $item) => $item['status'] === 'warning'));

        return response()->json([
            'operation_id' => $operationId,
            'checked_at_utc' => now('UTC')->toIso8601String(),
            'critical_count' => $critical,
            'warning_count' => $warnings,
            'items' => $items,
        ]);
    }

    /**
     * Match the real backup writer's Storage::disk('local') root. A missing
     * subdirectory is not a failed backup: Flysystem can provision it during a
     * write, but that ability remains unverified until a real backup succeeds.
     * Keep GET/report validation read-only and never expose server paths.
     */
    private function backupStorageItem(): array
    {
        $title = 'Managed backup storage';
        $disk = Storage::disk('local');
        // A symlinked root can make an external writable directory look like
        // protected application storage. A trailing slash would dereference
        // the link during is_link(), so normalize it before checking.
        $root = rtrim($disk->path(''), '/\\') ?: DIRECTORY_SEPARATOR;
        if (is_link($root)) {
            return $this->item('backups', $title, 'error', $title,
                'The configured backup storage root is a symbolic link and cannot be verified as safe. Server paths and exception details were withheld.');
        }

        $directory = $disk->path('backups');

        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            return $this->item('backups', $title, 'error', $title,
                'The configured backup target is not a safe directory. Server paths and exception details were withheld.');
        }

        if (is_dir($directory)) {
            return $this->storageItem('backups', $title, $directory);
        }

        $root = $disk->path('');
        if (is_dir($root) && is_writable($root)) {
            return $this->item('backups', $title, 'warning', $title,
                'Backup directory has not been provisioned yet. Its storage root appears writable, but a verified backup is still required.');
        }

        return $this->item('backups', $title, 'error', $title,
            'The configured backup storage root could not be verified as writable. Server paths and exception details were withheld.');
    }

    private function storageItem(string $key, string $title, string $path): array
    {
        $ok = is_dir($path) && is_writable($path);

        return $this->item(
            $key,
            $title,
            $ok ? 'valid' : 'error',
            $title,
            $ok
                ? 'This managed storage target was verified as available and writable.'
                : 'This managed storage target could not be verified as writable. Server paths and exception details were withheld.',
        );
    }

    private function item(string $key, string $title, string $status, string $value, string $message): array
    {
        return compact('key', 'title', 'status', 'value', 'message');
    }
}
