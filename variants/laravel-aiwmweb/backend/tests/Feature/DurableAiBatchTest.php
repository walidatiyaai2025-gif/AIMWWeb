<?php

namespace Tests\Feature;

use App\AI\Durable\BatchService;
use App\AI\Durable\BoundedDatabaseQueue;
use App\AI\Durable\ExecutionPermit;
use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\Jobs\GenerateSuggestionJob;
use App\Jobs\SyncSiteJob;
use App\Models\AiModelProfile;
use App\Models\AiProviderConfig;
use App\Models\AiProviderProfile;
use App\Models\Approval;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DurableAiBatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantMembership $membership;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cpanel.enabled' => true, 'cpanel.ai_enabled' => true, 'cpanel.cron_enabled' => true, 'cpanel.preflight_passed' => true, 'cpanel.min_interval' => 0, 'cpanel.daily_calls' => 1000, 'cpanel.daily_tokens' => 10000000, 'cpanel.batch_budget_usd' => 100, 'cpanel.daily_budget_usd' => 100]);
        $this->tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        app(TenantContext::class)->activate($this->tenant);
        $this->membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'Owner']);
        $permission = Permission::query()->create(['name' => 'ai.use']);
        $role->permissions()->attach($permission, ['tenant_id' => $this->tenant->id]);
        $this->membership->roles()->attach($role, ['tenant_id' => $this->tenant->id]);
        app(TenantContext::class)->activate($this->tenant, $this->membership);
        $this->site = Site::query()->create(['name' => 'Alpha site', 'url' => 'https://alpha.example', 'status' => 'active']);
        $this->actingAs($user);
    }

    private function actions(int $count = 50): array
    {
        return array_map(fn ($i) => ['key' => (string) $i, 'request' => ['workflow' => 'test', 'user_prompt' => 'Action '.$i]], range(1, $count));
    }

    private function provider(?int $failAt = null, bool $crash = false): object
    {
        $fake = new class($failAt, $crash) implements AiGenerator
        {
            public array $calls = [];

            public int $active = 0;

            public int $maximum = 0;

            public function __construct(private ?int $failAt, private bool $crash) {}

            public function generate(array $request): array
            {
                app(ExecutionPermit::class)->beforeProviderCall();
                $this->active++;
                $this->maximum = max($this->maximum, $this->active);
                try {
                    // A simultaneous second invocation must return without a call.
                    if (app(BatchService::class)->runOne() !== 'busy') {
                        throw new \RuntimeException('Concurrent worker entered provider.');
                    }
                    $this->calls[] = $request['user_prompt'];
                    if ($this->crash) {
                        throw new \RuntimeException('Crash after provider response before persistence');
                    }
                    if (count($this->calls) === $this->failAt) {
                        throw new AiPlatformException(AiFailureKind::RateLimit, 'Rate limit', false, 429, 120);
                    }

                    return ['provider' => 'mock', 'model' => 'mock', 'correlation_id' => (string) Str::uuid(), 'content' => $request['user_prompt'], 'structured' => ['text' => $request['user_prompt']]];
                } finally {
                    $this->active--;
                }
            }
        };
        $this->app->instance(AiGenerator::class, $fake);

        return $fake;
    }

    public function test_fifty_actions_commit_incrementally_without_overlap(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(), $this->site->id);
        $fake = $this->provider();
        for ($i = 1; $i <= 50; $i++) {
            $this->assertSame('completed', app(BatchService::class)->runOne());
            $this->assertSame($i, app(BatchService::class)->status($batch['id'])['completed']);
            $this->assertSame($i, DB::table('durable_ai_items')->where('state', 'completed')->whereNotNull('result')->count());
        }
        $this->assertSame(1, $fake->maximum);
        $this->assertSame('completed', app(BatchService::class)->status($batch['id'])['state']);
        $this->assertSame('idle', app(BatchService::class)->runOne());
        $this->assertCount(50, array_unique($fake->calls));
    }

    public function test_429_at_eighteen_preserves_seventeen_and_resumes_without_repeating_successes(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(), $this->site->id);
        $fake = $this->provider(18);
        for ($i = 0; $i < 18; $i++) {
            app(BatchService::class)->runOne();
        }
        $status = app(BatchService::class)->status($batch['id']);
        $this->assertSame(17, $status['completed']);
        $this->assertSame('paused', $status['items'][17]['state']);
        $this->assertSame('pending', $status['items'][18]['state']);
        $this->assertSame(0, $status['items'][18]['attempts']);
        $this->travel(121)->seconds();
        app(BatchService::class)->control($batch['id'], 'resume');
        for ($i = 0; $i < 33; $i++) {
            $this->assertSame('completed', app(BatchService::class)->runOne());
        }
        $this->assertSame(50, app(BatchService::class)->status($batch['id'])['completed']);
        foreach (range(1, 17) as $i) {
            $this->assertSame(1, count(array_filter($fake->calls, fn ($call) => $call === 'Action '.$i)));
        }
    }

    public function test_ambiguous_provider_result_is_never_automatically_replayed(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(2), $this->site->id);
        $fake = $this->provider(crash: true);
        app(BatchService::class)->runOne();
        $this->travel(200)->seconds();
        $this->assertSame('busy', app(BatchService::class)->runOne());
        $this->assertSame('needs_reconciliation', app(BatchService::class)->status($batch['id'])['items'][0]['state']);
        $this->assertCount(1, $fake->calls);
    }

    public function test_duplicate_submissions_return_same_persisted_batch(): void
    {
        $first = app(BatchService::class)->submit($this->actions(), $this->site->id, 'stable-key');
        $second = app(BatchService::class)->submit($this->actions(), $this->site->id, 'stable-key');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(50, DB::table('durable_ai_items')->count());
    }

    public function test_expired_owner_is_not_stolen_and_fences_old_result(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(2), $this->site->id);
        $item = DB::table('durable_ai_items')->where('batch_id', $batch['id'])->orderBy('position')->first();
        DB::table('durable_ai_items')->where('id', $item->id)->update(['state' => 'running']);
        DB::table('durable_ai_gate')->where('id', 1)->update(['owner' => (string) Str::uuid(), 'item_id' => $item->id, 'expires_at' => now()->subSecond()]);
        $this->assertSame('busy', app(BatchService::class)->runOne());
        $this->assertSame('needs_reconciliation', app(BatchService::class)->status($batch['id'])['items'][0]['state']);
    }

    public function test_foreign_tenant_cannot_read_results_or_control_batch(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(1), $this->site->id);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        app(TenantContext::class)->activate($beta);
        $member = TenantMembership::query()->create(['user_id' => $this->membership->user_id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'Owner']);
        $permission = Permission::query()->create(['name' => 'ai.use']);
        $role->permissions()->attach($permission, ['tenant_id' => $beta->id]);
        $member->roles()->attach($role, ['tenant_id' => $beta->id]);
        app(TenantContext::class)->activate($beta, $member);
        $this->expectException(HttpException::class);
        app(BatchService::class)->status($batch['id']);
    }

    public function test_disabled_profile_makes_no_calls_and_budget_exhaustion_pauses(): void
    {
        app(BatchService::class)->submit($this->actions(2), $this->site->id);
        $fake = $this->provider();
        config(['cpanel.ai_enabled' => false]);
        $this->assertSame('disabled', app(BatchService::class)->runOne());
        config(['cpanel.ai_enabled' => true, 'cpanel.daily_calls' => 0]);
        $this->assertSame('budget_exhausted', app(BatchService::class)->runOne());
        $this->assertCount(0, $fake->calls);
    }

    public function test_pause_resume_and_cancel_preserve_completed_results(): void
    {
        $batch = app(BatchService::class)->submit($this->actions(3), $this->site->id);
        $fake = $this->provider();
        app(BatchService::class)->runOne();
        $saved = DB::table('durable_ai_items')->where('state', 'completed')->value('result');
        app(BatchService::class)->control($batch['id'], 'pause');
        $this->assertSame('idle', app(BatchService::class)->runOne());
        app(BatchService::class)->control($batch['id'], 'resume');
        app(BatchService::class)->runOne();
        app(BatchService::class)->control($batch['id'], 'cancel');
        $this->assertSame('idle', app(BatchService::class)->runOne());
        $this->assertSame(2, app(BatchService::class)->status($batch['id'])['completed']);
        $this->assertSame($saved, DB::table('durable_ai_items')->orderBy('position')->value('result'));
        $this->assertCount(2, $fake->calls);
    }

    public function test_http_submission_is_202_and_polling_never_generates(): void
    {
        $fake = $this->provider();
        $response = $this->postJson('/api/tenants/alpha/ai-batches', ['submission_key' => 'http', 'site_id' => $this->site->id, 'actions' => $this->actions(3)]);
        $response->assertStatus(202)->assertJsonPath('data.total', 3);
        $this->getJson($response->json('status_url'))->assertOk()->assertJsonPath('data.completed', 0);
        $this->assertCount(0, $fake->calls);
    }

    public function test_revoked_membership_cannot_execute_previously_submitted_work(): void
    {
        app(BatchService::class)->submit($this->actions(1), $this->site->id);
        $fake = $this->provider();
        $this->membership->update(['status' => 'suspended']);
        $this->assertSame('paused', app(BatchService::class)->runOne());
        $this->assertCount(0, $fake->calls);
    }

    public function test_direct_provider_calls_require_a_worker_permit(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ExecutionPermit::class)->beforeProviderCall();
    }

    public function test_upgrade_adds_durable_tables_without_changing_existing_users_or_key(): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $key = config('app.key');
        Schema::drop('durable_ai_items');
        Schema::drop('durable_ai_batches');
        Schema::drop('durable_ai_gate');
        $migration = require database_path('migrations/2026_10_29_200000_create_durable_ai_batches.php');
        $migration->up();
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($key, config('app.key'));
        $this->assertSame(1, DB::table('durable_ai_gate')->count());
    }

    public function test_cpanel_setup_routes_are_inaccessible(): void
    {
        $this->get('/setup')->assertNotFound();
        $this->post('/setup')->assertNotFound();
    }

    public function test_php83_job_round_trip_preserves_readonly_tenant_provenance(): void
    {
        $job = new SyncSiteJob($this->tenant->id, $this->site->id, 1);
        $restored = unserialize(serialize($job));
        $this->assertSame($job->tenantId, $restored->tenantId);
        $this->assertSame($job->correlationId, $restored->correlationId);
        $this->assertSame($job->siteId, $restored->siteId);
    }

    public function test_database_queue_admission_cannot_grow_past_its_cap(): void
    {
        config(['cpanel.max_queued_jobs' => 1]);
        $queue = new BoundedDatabaseQueue(DB::connection(), 'jobs', 'default');
        $queue->pushRaw('{"job":"mock"}');
        try {
            $queue->pushRaw('{"job":"another"}');
            $this->fail('Second queue insertion should be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('capacity', $error->getMessage());
        }
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_populated_ai_evidence_cannot_be_removed_by_down_migration(): void
    {
        app(BatchService::class)->submit($this->actions(1), $this->site->id);
        $migration = require database_path('migrations/2026_10_29_200000_create_durable_ai_batches.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_legacy_suggestion_uses_saved_site_content_and_receipt_once(): void
    {
        $content = SyncedContent::query()->create(['site_id' => $this->site->id, 'resource_type' => 'post', 'remote_id' => 1, 'slug' => 'original', 'content' => 'Original saved content']);
        $audit = SeoAudit::query()->create(['site_id' => $this->site->id, 'actor_user_id' => $this->membership->user_id]);
        $finding = SeoFinding::query()->create(['seo_audit_id' => $audit->id, 'synced_content_id' => $content->id, 'code' => 'title_missing', 'severity' => 'high', 'recommendation' => 'Add title']);
        $suggestion = Suggestion::query()->create(['site_id' => $this->site->id, 'seo_finding_id' => $finding->id, 'synced_content_id' => $content->id, 'actor_user_id' => $this->membership->user_id, 'before_state' => []]);
        AiProviderConfig::query()->create(['provider' => 'test', 'endpoint' => 'https://mock.example/v1/chat/completions', 'model' => 'mock', 'encrypted_api_key' => 'fixture-only', 'enabled' => true]);
        $profile = AiProviderProfile::query()->create(['provider_key' => 'mock', 'adapter_key' => 'openai-compatible', 'display_name' => 'Mock', 'endpoint' => 'https://mock.example/v1', 'enabled' => true, 'readiness_state' => 'READY']);
        AiModelProfile::query()->create(['ai_provider_profile_id' => $profile->id, 'model_key' => 'mock', 'display_name' => 'Mock', 'capabilities' => ['text_generation'], 'enabled' => true, 'input_cost_per_million' => 0, 'output_cost_per_million' => 0, 'currency' => 'USD']);
        Http::fake(['mock.example/*' => Http::response(['id' => 'mock-receipt', 'choices' => [['message' => ['content' => '{"title":"Review me"}']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4]])]);
        $job = new GenerateSuggestionJob($this->tenant->id, $suggestion->id);
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
        $content->update(['content' => 'Changed after submission']);
        $this->assertSame('completed', app(BatchService::class)->runOne());
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        $sentPrompt = $recorded[0][0]['messages'][1]['content'];
        $this->assertStringContainsString('Original saved content', $sentPrompt);
        $this->assertStringNotContainsString('Changed after submission', $sentPrompt);
        $this->assertSame('ready', $suggestion->fresh()->status);
        $this->assertSame(1, Approval::query()->count());
        $this->assertSame('mock-receipt', json_decode(DB::table('durable_ai_items')->value('usage'), true)['provider_request_id']);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('durable_ai_batches')->count());
    }
}
