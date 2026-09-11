<?php

namespace Tests\Feature;

use App\Jobs\ExecutionJobConfiguration;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Execution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class ExecutionJobConfigurationParityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-CC236E83A3';

    public function test_canonical_execution_job_configuration_is_bound_to_the_existing_laravel_execution_model(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('background_job', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('job:ExecutionJobConfiguration', $operation['route_screen']);
        $this->assertSame('ExecutionJobConfiguration', $operation['background_job']);
        $this->assertSame(
            'src/AIWordPressManager.Persistence/Configurations/ExecutionJobConfiguration.cs',
            $operation['current_source'],
        );
        $this->assertSame(self::OPERATION_ID, ExecutionJobConfiguration::OPERATION_ID);
        $this->assertSame('executions', ExecutionJobConfiguration::TABLE);
        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive(Execution::class),
            'Execution persistence must remain tenant-scoped through TenantContext-backed BelongsToTenant isolation.',
        );
    }

    public function test_execution_job_schema_preserves_required_metadata_indexes_and_site_cascade(): void
    {
        $this->assertTrue(Schema::hasColumns('executions', [
            'id',
            'tenant_id',
            'site_id',
            'job_type',
            'status',
            'progress_percent',
            'current_step',
            'failure',
            'concurrency_token',
            'created_at',
        ]));

        $indexes = collect(Schema::getIndexes('executions'));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => in_array('status', $index['columns'] ?? [], true)
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => in_array('created_at', $index['columns'] ?? [], true)
        ));

        $siteForeignKey = collect(Schema::getForeignKeys('executions'))->first(
            fn (array $foreignKey): bool => in_array('site_id', $foreignKey['columns'] ?? [], true)
        );

        $this->assertNotNull($siteForeignKey);
        $this->assertSame('cascade', strtolower((string) ($siteForeignKey['on_delete'] ?? '')));
    }

    public function test_configuration_enforces_source_field_bounds_and_concurrency_invariants_fail_closed(): void
    {
        $configuration = new ExecutionJobConfiguration;
        $configuration->validate('SyncSite', 'Running', 'Starting', null, 0, 0);
        $configuration->validate(
            str_repeat('j', ExecutionJobConfiguration::JOB_TYPE_MAX_LENGTH),
            str_repeat('s', ExecutionJobConfiguration::STATUS_MAX_LENGTH),
            str_repeat('c', ExecutionJobConfiguration::CURRENT_STEP_MAX_LENGTH),
            str_repeat('e', ExecutionJobConfiguration::ERROR_DETAILS_MAX_LENGTH),
            100,
            9,
        );

        $invalid = [
            ['', 'Running', 'Starting', null, 0, 0],
            [str_repeat('j', ExecutionJobConfiguration::JOB_TYPE_MAX_LENGTH + 1), 'Running', 'Starting', null, 0, 0],
            ['SyncSite', str_repeat('s', ExecutionJobConfiguration::STATUS_MAX_LENGTH + 1), 'Starting', null, 0, 0],
            ['SyncSite', 'Running', str_repeat('c', ExecutionJobConfiguration::CURRENT_STEP_MAX_LENGTH + 1), null, 0, 0],
            ['SyncSite', 'Running', 'Starting', str_repeat('e', ExecutionJobConfiguration::ERROR_DETAILS_MAX_LENGTH + 1), 0, 0],
            ['SyncSite', 'Running', 'Starting', null, -1, 0],
            ['SyncSite', 'Running', 'Starting', null, 101, 0],
            ['SyncSite', 'Running', 'Starting', null, 0, -1],
        ];

        foreach ($invalid as $arguments) {
            try {
                $configuration->validate(...$arguments);
                $this->fail('Invalid ExecutionJobConfiguration state must fail closed.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_migration_maps_source_error_details_to_failure_and_keeps_tenant_scoped_execution_storage(): void
    {
        $migration = (string) file_get_contents(
            database_path('migrations/2026_09_11_100000_harden_execution_job_configuration_parity.php')
        );

        $this->assertStringContainsString('ExecutionJobConfiguration::ERROR_DETAILS_MAX_LENGTH', $migration);
        $this->assertStringContainsString("string('failure'", $migration);
        $this->assertStringContainsString("index('status'", $migration);
        $this->assertStringContainsString("index('created_at'", $migration);
        $this->assertStringContainsString('concurrency_token', $migration);
        $this->assertContains(
            BelongsToTenant::class,
            class_uses_recursive(Execution::class),
            'The canonical job configuration must not bypass tenant-scoped TenantContext isolation.',
        );
    }
}
