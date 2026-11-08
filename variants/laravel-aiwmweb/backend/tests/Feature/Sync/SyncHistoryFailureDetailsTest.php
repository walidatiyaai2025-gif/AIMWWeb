<?php

namespace Tests\Feature\Sync;

use App\Connector\WordPressGateway;
use App\Jobs\SyncSiteJob;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Services\SeoManagerService;
use App\Sites\SiteOperationHistoryService;
use App\Sync\SyncDiagnosticFormatter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SyncHistoryFailureDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_failure_history_is_actionable_without_disclosing_untrusted_error_payloads(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        app(TenantContext::class)->activate($tenant);
        $site = Site::query()->create(['name' => 'WP', 'url' => 'https://wp.test']);
        $run = SyncRun::query()->create(['site_id' => $site->id, 'status' => 'queued']);

        $gateway = $this->createMock(WordPressGateway::class);
        $gateway->expects($this->once())->method('content')->willThrowException(
            new RuntimeException('HTTP 401 invalid signature password=private-value https://wp.test/?token=unsafe')
        );

        try {
            (new SyncSiteJob($tenant->id, $site->id, $run->id))->handle(
                $gateway,
                app(SeoManagerService::class),
                new SiteOperationHistoryService,
            );
            $this->fail('The failed WordPress sync must still propagate the exception.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }

        $entry = SiteOperationHistory::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertSame('failed', $entry->status);
        $this->assertSame('synchronization', $entry->operation);
        $this->assertSame('WordPress synchronization failed.', $entry->message);
        $this->assertSame($run->id, $entry->details['sync_run_id']);
        $this->assertSame('WP_SYNC_AUTHENTICATION', $entry->details['failure_code']);
        $this->assertSame('authentication', $entry->details['failure_category']);
        $this->assertStringContainsString('credentials', $entry->details['failure_message']);
        $this->assertNotEmpty($entry->correlation_id);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringNotContainsString('private-value', (string) $run->fresh()->failure);
        $this->assertStringNotContainsString('private-value', json_encode($entry->toArray(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('unsafe', json_encode($entry->toArray(), JSON_THROW_ON_ERROR));

        app(TenantContext::class)->forget();
    }

    public function test_failure_categories_are_stable_and_never_echo_secrets(): void
    {
        foreach ([
            ['HTTP 403 forbidden', 'AUTHENTICATION'],
            ['HTTP 429 quota exceeded', 'RATE_LIMIT'],
            ['invalid_protocol version mismatch', 'PROTOCOL'],
            ['cURL 28 connection timed out', 'TRANSPORT'],
            ['HTTP 503 service unavailable', 'REMOTE_SERVICE'],
            ['SQLSTATE failed writing database', 'STORAGE'],
            ['Unexpected failure', 'UNKNOWN'],
        ] as [$error, $expected]) {
            $failure = SyncDiagnosticFormatter::historyFailure(new RuntimeException(
                $error.' password=secret-value https://wp.test/?token=unsafe'
            ));
            $this->assertSame('WP_SYNC_'.$expected, $failure['failure_code']);
            $this->assertSame(strtolower($expected), $failure['failure_category']);
            $this->assertStringNotContainsString('secret-value', json_encode($failure, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('unsafe', json_encode($failure, JSON_THROW_ON_ERROR));
        }
    }
}
