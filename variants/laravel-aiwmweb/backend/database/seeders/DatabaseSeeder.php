<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed only non-secret, editable workspace reference settings.
     * Identity and the first platform administrator are created exclusively
     * by the authenticated hosting-owner setup transaction.
     * Commercial plans/prices must be configured explicitly by administrators.
     */
    public function run(): void
    {
        $this->call(ProductionWorkspaceSeeder::class);
    }
}
