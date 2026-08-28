<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseSetupReadService
{
    /**
     * @return array{complete: bool, driver: string, database_reachable: bool, migrations_ready: bool}
     */
    public function status(): array
    {
        $driver = (string) config('database.default', 'unknown');

        try {
            DB::connection()->getPdo();
            $migrationsReady = Schema::hasTable('migrations');
        } catch (Throwable) {
            return [
                'complete' => false,
                'driver' => $driver,
                'database_reachable' => false,
                'migrations_ready' => false,
            ];
        }

        return [
            'complete' => $migrationsReady,
            'driver' => $driver,
            'database_reachable' => true,
            'migrations_ready' => $migrationsReady,
        ];
    }
}
