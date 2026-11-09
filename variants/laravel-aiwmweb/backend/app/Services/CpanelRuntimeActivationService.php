<?php

namespace App\Services;

use App\AI\Platform\Enums\ProviderReadiness;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\TenantSecret;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Explicit platform-owner activation of a VERIFIED cPanel runtime.
 *
 * No action here creates hosting Cron jobs, calls a remote AI model, drains a
 * queue, resets data or guesses credentials. The protected private Cron
 * entrypoint must be installed and observed before background work is enabled.
 */
final class CpanelRuntimeActivationService
{
    public function __construct(private readonly RuntimeHealthService $health) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $inspector = $this->health->inspectorEvidence();

        return [
            'profile' => config('cpanel.enabled') ? 'cpanel' : 'other',
            'queue_driver' => config('queue.default') === 'database' ? 'database' : 'not_database',
            'queue_enabled' => (bool) config('cpanel.cron_enabled') && (bool) config('cpanel.preflight_passed'),
            'ai_enabled' => (bool) config('cpanel.ai_enabled'),
            'cron_invocation' => $inspector['cron_invocation'] ?? ['status' => 'unavailable'],
            'worker_cycle' => $inspector['worker_cycle'] ?? ['status' => 'unavailable'],
            'scheduler_heartbeat' => $inspector['scheduler_heartbeat'] ?? ['status' => 'unavailable'],
            'queue_blockers' => $this->queueBlockers($inspector),
            'ai_blockers' => $this->aiBlockers($inspector),
            // An active switch is never evidence of an actual generated response.
            'ai_generation_verified' => false,
        ];
    }

    /**
     * The caller must already pass web + auth + platform.admin + CSRF + throttle.
     * Return only fixed status codes; never disclose secrets, .env or host paths.
     */
    public function activate(string $mode, int $actorId): void
    {
        if (! config('cpanel.enabled')) {
            throw new RuntimeException('cpanel_profile_required');
        }

        if ($mode === 'queue') {
            if ($this->queueBlockers($this->health->inspectorEvidence()) !== []) {
                throw new RuntimeException('queue_preflight_blocked');
            }

            $this->updatePrivateEnvironment([
                'AIMW_RUNTIME_PROFILE' => 'cpanel',
                'QUEUE_CONNECTION' => 'database',
                'CACHE_STORE' => 'database',
                'SESSION_DRIVER' => 'database',
                'CPANEL_PREFLIGHT_PASSED' => 'true',
                'CPANEL_CRON_ENABLED' => 'true',
            ]);
            config(['cpanel.preflight_passed' => true, 'cpanel.cron_enabled' => true]);
        } elseif ($mode === 'ai') {
            if ($this->aiBlockers($this->health->inspectorEvidence()) !== []) {
                throw new RuntimeException('ai_preflight_blocked');
            }

            $this->updatePrivateEnvironment(['CPANEL_AI_ENABLED' => 'true']);
            config(['cpanel.ai_enabled' => true]);
        } elseif ($mode === 'disable_ai') {
            // Pausing new AI admissions never erases queued or completed jobs.
            $this->updatePrivateEnvironment(['CPANEL_AI_ENABLED' => 'false']);
            config(['cpanel.ai_enabled' => false]);
        } else {
            throw new RuntimeException('unsupported_runtime_action');
        }

        Log::notice('cpanel.runtime.activation', ['action' => $mode, 'actor_id' => $actorId]);
    }

    /** @return list<string> */
    private function queueBlockers(array $inspector): array
    {
        $blocked = [];

        if (! config('cpanel.enabled')) {
            $blocked[] = 'cpanel_profile_required';
        }
        if (PHP_VERSION_ID < 80300 || ! extension_loaded('pdo_mysql')) {
            $blocked[] = 'hosting_php_preflight_required';
        }
        if (config('queue.default') !== 'database'
            || config('cache.default') !== 'database'
            || config('session.driver') !== 'database') {
            $blocked[] = 'database_runtime_drivers_required';
        }
        if (($inspector['cron_invocation']['status'] ?? '') !== 'ok') {
            $blocked[] = 'private_cron_not_observed';
        }

        try {
            if (DB::getDriverName() !== 'mysql') {
                $blocked[] = 'mysql_required';
            } else {
                DB::select('SELECT 1');
                foreach (['jobs', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'durable_ai_gate', 'durable_ai_batches', 'durable_ai_items'] as $table) {
                    if (! Schema::hasTable($table)) {
                        $blocked[] = 'required_database_tables_missing';
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $blocked[] = 'database_preflight_failed';
        }

        return array_values(array_unique($blocked));
    }

    /** @return list<string> */
    private function aiBlockers(array $inspector): array
    {
        $blocked = $this->queueBlockers($inspector);

        if (! config('cpanel.preflight_passed') || ! config('cpanel.cron_enabled')) {
            $blocked[] = 'queue_activation_required';
        }
        if (($inspector['worker_cycle']['status'] ?? '') !== 'ok') {
            $blocked[] = 'bounded_worker_receipt_missing';
        }
        if (($inspector['scheduler_heartbeat']['status'] ?? '') !== 'ok') {
            $blocked[] = 'scheduler_receipt_missing';
        }

        try {
            // Platform-level readiness check. Do not read/decrypt/return tenant
            // secrets or leak provider identity/model names to the page.
            $providers = AiProviderProfile::withoutGlobalScopes()
                ->where('enabled', true)
                ->where('readiness_state', ProviderReadiness::Ready->value)
                ->where('adapter_key', 'openai-compatible')
                ->get();

            $available = false;
            foreach ($providers as $provider) {
                if ($provider->automatic_failover
                    || (int) $provider->timeout_seconds > (int) config('cpanel.provider_timeout')
                    || ! $provider->readiness_checked_at
                    || $provider->readiness_checked_at->lt(now()->subDay())) {
                    continue;
                }

                $hasSecret = TenantSecret::withoutGlobalScopes()
                    ->where('name', $provider->secretName())->exists();
                if (! $hasSecret) {
                    continue;
                }

                $models = AiModelProfile::withoutGlobalScopes()
                    ->where('ai_provider_profile_id', $provider->getKey())
                    ->where('enabled', true)
                    ->get(['capabilities']);
                foreach ($models as $model) {
                    if (count(array_diff(['text_generation', 'structured_json'], (array) $model->capabilities)) === 0) {
                        $available = true;
                        break 2;
                    }
                }
            }

            if (! $available) {
                $blocked[] = 'provider_model_or_credential_not_ready';
            }
        } catch (Throwable) {
            $blocked[] = 'provider_readiness_unavailable';
        }

        return array_values(array_unique($blocked));
    }

    /** @param array<string, string> $values */
    private function updatePrivateEnvironment(array $values): void
    {
        $path = base_path('.env');
        $directory = storage_path('app/runtime-activation');
        if (! is_file($path) || is_link($path) || ! is_writable($path)
            || (is_dir($directory) ? is_link($directory) : ! @mkdir($directory, 0700, true))) {
            throw new RuntimeException('protected_environment_unavailable');
        }

        $lockPath = $directory.'/activation.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('activation_lock_unsafe');
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('runtime_activation_in_progress');
        }

        try {
            $original = file_get_contents($path);
            if (! is_string($original) || $original === '') {
                throw new RuntimeException('protected_environment_unavailable');
            }

            $updated = $original;
            foreach ($values as $key => $value) {
                if (! preg_match('/^[A-Z_]+$/D', $key) || ! in_array($value, ['true', 'false', 'cpanel', 'database'], true)) {
                    throw new RuntimeException('invalid_runtime_setting');
                }
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
                $found = preg_match_all($pattern, $updated);
                if ($found === false || $found > 1) {
                    throw new RuntimeException('ambiguous_runtime_setting');
                }
                $line = $key.'="'.$value.'"';
                $updated = $found === 1
                    ? (string) preg_replace($pattern, $line, $updated)
                    : rtrim($updated, "\r\n")."\n".$line."\n";
            }

            if ($original === $updated) {
                return;
            }

            $suffix = bin2hex(random_bytes(8));
            $backup = $directory.'/env-backup-'.$suffix.'.private';
            $temporary = $path.'.tmp-'.$suffix;
            if (file_put_contents($backup, $original, LOCK_EX) !== strlen($original)) {
                throw new RuntimeException('environment_backup_failed');
            }
            @chmod($backup, 0600);

            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)) {
                @unlink($temporary);
                throw new RuntimeException('environment_write_failed');
            }
            @chmod($temporary, 0600);
            if (! @rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('environment_activation_failed');
            }

            // Laravel config cache must not keep stale flags after an atomic
            // environment change. A subsequent request sees the new values.
            try {
                Artisan::call('config:clear');
            } catch (Throwable) {
                // Failed refresh is not a successful activation. The previous
                // private env is restored, and a verified recovery copy remains.
                @copy($backup, $path);
                @chmod($path, 0600);
                throw new RuntimeException('runtime_config_refresh_failed');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
