<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DatabaseSetupMutationService
{
    public function __construct(
        private readonly DatabaseSetupReadService $readService,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array{admin_name:string,admin_email:string,admin_password:string,tenant_name:string} $input */
    public function apply(array $input): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            throw new RuntimeException('The configured database is not reachable. Check the deployment environment and try again.');
        }

        Artisan::call('migrate', ['--force' => true]);

        if (! $this->readService->status()['migrations_ready']) {
            throw new RuntimeException('Database migrations did not reach the required repository state.');
        }

        foreach (['users', 'tenants', 'tenant_memberships'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Identity schema is incomplete after migration.');
            }
        }

        if (DB::table('users')->exists() || DB::table('tenants')->exists() || DB::table('tenant_memberships')->exists()) {
            throw new RuntimeException('Existing identity state was detected. First-run setup cannot claim or overwrite an existing installation.');
        }

        DB::transaction(function () use ($input): void {
            $tenant = Tenant::query()->create([
                'name' => $input['tenant_name'],
                'slug' => $this->uniqueTenantSlug($input['tenant_name']),
            ]);
            $user = User::query()->create([
                'name' => $input['admin_name'],
                'email' => Str::lower($input['admin_email']),
                'password' => $input['admin_password'],
            ]);

            $this->tenantContext->activate($tenant);
            try {
                $membership = TenantMembership::query()->create([
                    'user_id' => $user->id,
                    'status' => 'active',
                ]);
                $role = Role::query()->create(['name' => 'Owner']);
                $permissions = collect($this->discoverPermissions())
                    ->map(fn (string $name): Permission => Permission::query()->create(['name' => $name]));

                $role->permissions()->attach($permissions->pluck('id')->all(), ['tenant_id' => $tenant->id]);
                $membership->roles()->attach($role->id, ['tenant_id' => $tenant->id]);
            } finally {
                $this->tenantContext->forget();
            }
        });

        if (! $this->readService->status()['complete']) {
            throw new RuntimeException('Setup did not produce a complete, usable installation.');
        }
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'primary-workspace';
        $slug = $base;
        $suffix = 2;
        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** @return list<string> */
    private function discoverPermissions(): array
    {
        $permissions = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = File::get($file->getPathname());
            preg_match_all("/(?:authorize|hasPermission)\\(\\s*['\"]([^'\"]+)['\"]|permission:([A-Za-z0-9._-]+)/", $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $permission = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                if ($permission !== '') {
                    $permissions[$permission] = true;
                }
            }
        }

        $names = array_keys($permissions);
        sort($names);
        if ($names === []) {
            throw new RuntimeException('No application permission catalog could be derived; owner bootstrap is denied.');
        }

        return $names;
    }
}
