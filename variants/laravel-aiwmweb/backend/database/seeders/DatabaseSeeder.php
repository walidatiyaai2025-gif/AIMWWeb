<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Never create fake users or passwords on a production installation.
     * The first platform owner must be created only by the owner-authorized
     * setup flow, which enforces tenant membership and platform RBAC.
     *
     * Product reference data seeders will be registered here only once
     * they have explicit idempotent business keys and admin edit contracts.
     */
    public function run(): void
    {
        // Intentionally no anonymous or demonstration identities.
    }
}
