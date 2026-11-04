<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Safe, editable workspace defaults for the actual hosting-owner-created tenant.
 *
 * These are non-secret reference settings, not identities, commercial pricing,
 * credentials, external provider configuration, or demo data.
 */
final class ProductionWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            $this->seedTenant((int) $tenantId);
        }
    }

    public function seedTenant(int $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            // Lock the tenant to serialize a repeated or concurrent seed.
            $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            if ($tenant === null) {
                throw new RuntimeException('Cannot seed a nonexistent workspace.');
            }

            $defaults = [
                'workspace.display_name' => (string) $tenant->name,
                'workspace.locale' => (string) config('app.locale', 'en'),
                'workspace.timezone' => (string) config('app.timezone', 'UTC'),
            ];

            foreach ($defaults as $key => $value) {
                $exists = DB::table('scoped_settings')
                    ->where('tenant_id', $tenantId)
                    ->where('scope', 'tenant')
                    ->where('key', $key)
                    ->whereNull('site_key')
                    ->whereNull('user_id')
                    ->exists();

                // Never overwrite settings subsequently changed by an administrator.
                if ($exists) {
                    continue;
                }

                DB::table('scoped_settings')->insert([
                    'tenant_id' => $tenantId,
                    'user_id' => null,
                    'site_key' => null,
                    'scope' => 'tenant',
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'encrypted_value' => null,
                    'is_secret' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
