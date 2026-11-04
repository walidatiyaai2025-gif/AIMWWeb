<?php

namespace App\Services;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Out-of-band proof of control over the hosting filesystem.
 *
 * The secret never appears in a HTTP response, release ZIP or application log.
 * Operators read it from the private Laravel storage directory using cPanel.
 */
final class SetupOwnerProof
{
    public function path(): string
    {
        return (string) config('setup.owner_proof_file', storage_path('app/aiwm-install/owner-activation.secret'));
    }

    public function ensureIssued(): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private setup storage is unavailable.');
        }

        if (is_link($directory) || is_link($path)) {
            throw new RuntimeException('Unsafe setup ownership challenge path.');
        }

        if (is_file($path)) {
            return;
        }

        // Atomic creation ensures that anonymous page views cannot rotate the owner's proof.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            if (is_file($path) && ! is_link($path)) {
                return;
            }
            throw new RuntimeException('Could not create owner activation challenge.');
        }

        try {
            chmod($path, 0600);
            fwrite($handle, bin2hex(random_bytes(32)));
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    public function authorized(Request $request): bool
    {
        $path = $this->path();
        $submitted = $request->input('owner_activation_secret');
        if (! is_string($submitted) || ! preg_match('/^[a-f0-9]{64}$/D', $submitted)) {
            return false;
        }

        if (! is_file($path) || is_link($path)) {
            return false;
        }

        $expected = @file_get_contents($path);
        return is_string($expected) && hash_equals(trim($expected), $submitted);
    }

    public function consume(): void
    {
        $path = $this->path();
        if (is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
    }

    public function withLock(callable $action): mixed
    {
        $lock = $this->path().'.lock';
        if (is_link($lock)) {
            throw new RuntimeException('Unsafe setup lock.');
        }

        $handle = @fopen($lock, 'c');
        if ($handle === false) {
            throw new RuntimeException('Setup lock is unavailable.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another setup operation is in progress.');
            }
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
