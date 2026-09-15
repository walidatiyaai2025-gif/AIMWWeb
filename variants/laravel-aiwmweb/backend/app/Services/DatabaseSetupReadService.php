<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseSetupReadService
{
    /**
     * @return array{complete: bool, driver: string, database_reachable: bool, migrations_ready: bool, identity_ready: bool}
     */
    public function status(): array
    {
        $driver = (string) config('database.default', 'unknown');

        try {
            DB::connection()->getPdo();

            if (! Schema::hasTable('migrations')) {
                return $this->incomplete($driver, true, false, false);
            }

            $expectedMigrations = collect(File::files(database_path('migrations')))
                ->filter(static fn ($file): bool => $file->getExtension() === 'php')
                ->map(static fn ($file): string => pathinfo($file->getFilename(), PATHINFO_FILENAME));
            $ranMigrations = DB::table('migrations')->pluck('migration');
            $migrationsReady = $expectedMigrations->diff($ranMigrations)->isEmpty();
            $identityReady = $migrationsReady
                && Schema::hasTable('users')
                && Schema::hasTable('tenants')
                && Schema::hasTable('tenant_memberships')
                && DB::table('users')->exists()
                && DB::table('tenants')->exists()
                && DB::table('tenant_memberships')->exists();
        } catch (Throwable) {
            return $this->incomplete($driver, false, false, false);
        }

        return [
            'complete' => $migrationsReady && $identityReady,
            'driver' => $driver,
            'database_reachable' => true,
            'migrations_ready' => $migrationsReady,
            'identity_ready' => $identityReady,
        ];
    }

    /** @return array{complete: bool, driver: string, database_reachable: bool, migrations_ready: bool, identity_ready: bool} */
    private function incomplete(string $driver, bool $reachable, bool $migrationsReady, bool $identityReady): array
    {
        return [
            'complete' => false,
            'driver' => $driver,
            'database_reachable' => $reachable,
            'migrations_ready' => $migrationsReady,
            'identity_ready' => $identityReady,
        ];
    }
}
