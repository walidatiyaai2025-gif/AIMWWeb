<?php

namespace App\Http\Controllers;

use App\Platform\SystemUpdateService;
use App\Platform\VerifiedMysqlBackupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class SystemUpdateController extends Controller
{
    public function index(Request $request, SystemUpdateService $updates): View
    {
        $file = storage_path('app/system-updates/history.jsonl');
        $entries = [];

        if (is_file($file)) {
            // The journal is a local, append-only provenance log; limit display size.
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            foreach (array_slice($lines, -8) as $line) {
                $record = json_decode($line, true);

                if (is_array($record)) {
                    $entries[] = $record;
                }
            }
        }

        return view('platform.system-updates', [
            'state' => $updates->state(),
            'candidate' => $request->session()->get('system_update_candidate'),
            'history' => array_reverse($entries),
        ]);
    }

    public function check(Request $request, SystemUpdateService $updates): RedirectResponse
    {
        try {
            $latest = $updates->latest();
            $request->session()->flash('system_update_candidate', [
                'version' => $latest['version'],
                'sha' => $latest['sha'],
                'available' => $latest['available'],
            ]);

            return back()->with('status', $latest['available']
                ? 'A newer signed release has been verified.'
                : 'No newer approved signed release is available.');
        } catch (Throwable $e) {
            // A metadata lookup only reads an approved signed release; it never
            // starts maintenance or installs files. Do not expose upstream URLs,
            // credentials, stack traces or raw transport exceptions to the UI.
            report($e);

            $message = $e instanceof RuntimeException ? $e->getMessage() : '';
            $category = str_contains($message, 'timed out') || str_contains($message, 'connection')
                ? 'connection_timeout'
                : (str_contains($message, 'signature') ? 'signature_invalid' : 'metadata_unavailable');

            return back()
                ->with('system_update_check_failure', [
                    'phase' => 'release_metadata',
                    'category' => $category,
                ])
                ->withErrors(['update' => 'Signed release check failed before installation. No update was applied.']);
        }
    }

    /** Backup Now creates a verified encrypted snapshot; never performs a restore on live data. */
    public function backup(Request $request, VerifiedMysqlBackupService $backups): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:BACKUP']]);

        try {
            $receipt = $backups->backupNow((int) $request->user()->getAuthIdentifier());

            return back()->with('status', 'Encrypted MySQL backup verified: '.$receipt['id'].' ('.$receipt['rows'].' rows).');
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'update' => $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Database backup failed; no update was applied.',
            ]);
        }
    }

    /** Verify a signed ZIP without applying any changes to the installation. */
    public function verify(Request $request, SystemUpdateService $updates): RedirectResponse
    {
        $request->validate([
            'update_bundle' => ['required', 'file', 'mimes:zip', 'max:163840'],
        ]);

        try {
            $verified = $updates->verifyUpload($request->file('update_bundle'));
            $request->session()->flash('system_update_verified', $verified);

            return back()->with('status', 'Signed ZIP verified without installation. Select the bundle again to install.');
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors([
                'update' => $e instanceof RuntimeException ? $e->getMessage() : 'Package verification failed.',
            ]);
        }
    }

    /**
     * Accepts exactly one signed transport bundle, not arbitrary uploaded PHP.
     * The signing key is never disclosed to or supplied by the browser.
     */
    public function upload(Request $request, SystemUpdateService $updates): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'in:INSTALL'],
            'update_bundle' => ['required', 'file', 'mimes:zip', 'max:163840'],
        ]);

        try {
            $receipt = $updates->install(
                (int) $request->user()->getAuthIdentifier(),
                $request->file('update_bundle'),
            );

            return redirect()->route('platform.updates.index')->with(
                'status',
                'Installed uploaded signed release '.$receipt['version'].' ('.substr($receipt['sha'], 0, 12).').'
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('platform.updates.index')->withErrors([
                'update' => $e instanceof RuntimeException ? $e->getMessage() : 'Upload failed; review server logs and recovery snapshot.',
            ]);
        }
    }

    public function install(Request $request, SystemUpdateService $updates): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:INSTALL']]);

        try {
            $receipt = $updates->install((int) $request->user()->getAuthIdentifier());

            return redirect()->route('platform.updates.index')->with(
                'status',
                'Installed signed release '.$receipt['version'].' ('.substr($receipt['sha'], 0, 12).').'
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('platform.updates.index')->withErrors([
                'update' => $e instanceof RuntimeException ? $e->getMessage() : 'Update failed; review server logs and recovery snapshot.',
            ]);
        }
    }
}
