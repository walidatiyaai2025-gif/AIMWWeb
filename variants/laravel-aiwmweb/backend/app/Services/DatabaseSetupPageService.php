<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;

final class DatabaseSetupPageService
{
    public const FAILURE_MESSAGE = 'Setup could not be completed safely. Verify the configured database and existing installation state, then try again.';

    public function __construct(private readonly DatabaseSetupReadService $readService) {}

    /**
     * @return array{complete: bool, driver: string, database_reachable: bool, migrations_ready: bool, identity_ready: bool}
     */
    public function status(): array
    {
        return $this->readService->status();
    }

    /**
     * Canonical parity operation AIMW-CONT-43AF0076B5 adapts
     * DatabaseSetupService.RenderPage to the Laravel setup-page boundary.
     *
     * Render the first-run setup page from authoritative runtime status.
     *
     * Database/provider credentials remain deployment-owned and are never
     * accepted as page-model input. Blade's escaped interpolation owns error
     * encoding, while callers pass only bounded operator-safe messages.
     *
     * @param  array{complete: bool, driver: string, database_reachable: bool, migrations_ready: bool, identity_ready: bool}|null  $setupStatus
     */
    public function render(?string $error = null, int $statusCode = 200, ?array $setupStatus = null): Response
    {
        $errors = request()->hasSession()
            ? request()->session()->get('errors', new ViewErrorBag)
            : new ViewErrorBag;

        return response()->view('setup', [
            'status' => $setupStatus ?? $this->status(),
            'error' => $error,
            'errors' => $errors,
        ], $statusCode);
    }
}
