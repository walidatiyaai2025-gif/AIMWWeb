<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use Illuminate\Http\JsonResponse;

final class ConfigurationValidationController extends Controller
{
    public const COPY_REPORT_OPERATION_ID = 'AIMW-BILL-39BB044AF2';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function show(string $tenant): JsonResponse
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
            $this->storageItem('backups', 'Managed backup storage', storage_path('app/backups')),
        ];

        $critical = count(array_filter($items, fn (array $item) => $item['status'] === 'error'));
        $warnings = count(array_filter($items, fn (array $item) => $item['status'] === 'warning'));

        return response()->json([
            'operation_id' => self::COPY_REPORT_OPERATION_ID,
            'checked_at_utc' => now('UTC')->toIso8601String(),
            'critical_count' => $critical,
            'warning_count' => $warnings,
            'items' => $items,
        ]);
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
