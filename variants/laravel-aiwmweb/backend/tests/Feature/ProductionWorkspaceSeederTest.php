<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\ProductionWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionWorkspaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_non_secret_editable_workspace_defaults_without_demo_users(): void
    {
        config()->set('app.locale', 'ar');
        config()->set('app.timezone', 'Asia/Kuwait');

        $tenant = Tenant::query()->create(['name' => 'Owner Workspace', 'slug' => 'owner-workspace']);

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $settings = DB::table('scoped_settings')->where('tenant_id', $tenant->id)
            ->where('scope', 'tenant')->orderBy('key')->get();

        $this->assertCount(3, $settings);
        $this->assertSame([
            'workspace.display_name' => 'Owner Workspace',
            'workspace.locale' => 'ar',
            'workspace.timezone' => 'Asia/Kuwait',
        ], $settings->mapWithKeys(fn ($row) => [$row->key => json_decode($row->value, true)])->all());

        foreach ($settings as $row) {
            $this->assertFalse((bool) $row->is_secret);
            $this->assertNull($row->encrypted_value);
            $this->assertNull($row->site_key);
            $this->assertNull($row->user_id);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenant_memberships', 0);
    }

    public function test_repeated_seed_preserves_admin_changes_and_does_not_duplicate_settings(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Workspace', 'slug' => 'workspace']);
        $seeder = app(ProductionWorkspaceSeeder::class);
        $seeder->seedTenant($tenant->id);

        DB::table('scoped_settings')->where('tenant_id', $tenant->id)
            ->where('key', 'workspace.locale')
            ->update(['value' => json_encode('en', JSON_THROW_ON_ERROR)]);

        config()->set('app.locale', 'ar');
        $seeder->seedTenant($tenant->id);
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $rows = DB::table('scoped_settings')->where('tenant_id', $tenant->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame('en', json_decode((string) $rows->firstWhere('key', 'workspace.locale')->value, true));

        $other = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        $seeder->seedTenant($other->id);
        $this->assertDatabaseCount('scoped_settings', 6);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_nonexistent_tenant_is_rejected_without_mutating_any_settings(): void
    {
        try {
            app(ProductionWorkspaceSeeder::class)->seedTenant(999999);
            $this->fail('Nonexistent workspace was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Cannot seed a nonexistent workspace.', $e->getMessage());
        }

        $this->assertDatabaseCount('scoped_settings', 0);
    }
}
