<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabasePathTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-F6C1A04662';

    public function test_exact_canonical_operation_is_get_database_path(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:ApplicationPathService', $operation['route_screen']);
        $this->assertSame('GetDatabasePath', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_laravel_sqlite_file_fallback_uses_the_application_database_directory(): void
    {
        $configuration = (string) file_get_contents(config_path('database.php'));
        $databaseFile = database_path('database.sqlite');
        $databaseRoot = rtrim(database_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->assertStringContainsString(
            "'database' => env('DB_DATABASE', database_path('database.sqlite'))",
            $configuration,
        );
        $this->assertStringStartsWith($databaseRoot, $databaseFile);
        $this->assertSame('database.sqlite', basename($databaseFile));
        $this->assertStringNotContainsString('tenant', strtolower($databaseFile));
        $this->assertStringNotContainsString('user', strtolower($databaseFile));
    }

    public function test_runtime_database_locator_is_connection_configuration_not_a_fabricated_file_path(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $row = DB::selectOne('select 1 as value');

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->value);
    }

    public function test_production_example_uses_mysql_without_inventing_a_local_database_path(): void
    {
        $environment = "\n".(string) file_get_contents(base_path('.env.example'))."\n";

        $this->assertStringContainsString("\nDB_CONNECTION=mysql\n", $environment);
        $this->assertStringContainsString("\nDB_DATABASE=laravel_aiwmweb\n", $environment);
        $this->assertStringNotContainsString('DB_DATABASE=database/database.sqlite', $environment);
        $this->assertStringNotContainsString('DB_DATABASE=aiwpmanager.db', $environment);
    }

    public function test_database_path_contract_has_no_request_or_tenant_selected_input(): void
    {
        $configuration = (string) file_get_contents(config_path('database.php'));
        $fallback = "env('DB_DATABASE', database_path('database.sqlite'))";

        $this->assertStringContainsString($fallback, $configuration);
        $this->assertStringNotContainsString('request(', $configuration);
        $this->assertStringNotContainsString('TenantContext', $configuration);
        $this->assertStringNotContainsString('tenant_id', $configuration);
    }
}
