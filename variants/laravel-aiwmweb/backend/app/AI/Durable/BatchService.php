<?php

namespace App\AI\Durable;

use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\Authorization\TenantAuthorizer;
use App\Jobs\GenerateSuggestionJob;
use App\Models\AiGenerationRecord;
use App\Models\AiUsageRecord;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BatchService
{
    public function __construct(private readonly TenantContext $context, private readonly ExecutionPermit $permit) {}

    public function submit(array $actions, ?int $siteId = null, ?string $submissionKey = null): array
    {
        app(TenantAuthorizer::class)->authorize('ai.use');
        if ($siteId !== null) {
            Site::query()->findOrFail($siteId);
        }
        if (count($actions) < 1 || count($actions) > 100) {
            throw ValidationException::withMessages(['actions' => 'Provide 1–100 actions.']);
        }
        $keys = array_column($actions, 'key');
        if (count(array_unique($keys)) !== count($actions)) {
            throw ValidationException::withMessages(['actions' => 'Every action requires a unique key.']);
        }
        $hash = $this->hash(['site_id' => $siteId, 'actions' => $actions]);
        $tenantId = $this->context->id();
        $userId = $this->context->membership()->user_id;
        $key = hash('sha256', $submissionKey ?? $userId.':'.$hash);

        $id = DB::transaction(function () use ($actions, $siteId, $tenantId, $userId, $key, $hash): string {
            // A permanent singleton also serializes submission races. Never wait for
            // a provider here: provider work never holds this row transaction open.
            DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            $existing = DB::table('durable_ai_batches')->where('tenant_id', $tenantId)->where('submission_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Submission key already has different content.');

                return $existing->id;
            }
            $outstanding = DB::table('durable_ai_items as i')->join('durable_ai_batches as b', 'b.id', '=', 'i.batch_id')
                ->whereNull('b.cancelled_at')->whereNotIn('i.state', ['completed', 'failed'])->count();
            abort_if($outstanding + count($actions) > (int) config('cpanel.max_pending_actions'), 429, 'AI task admission capacity reached.');
            $id = (string) Str::uuid();
            DB::table('durable_ai_batches')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'site_id' => $siteId,
                'submission_key' => $key, 'request_hash' => $hash, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (array_values($actions) as $position => $action) {
                $itemKey = (string) ($action['key'] ?? '');
                if ($itemKey === '' || strlen($itemKey) > 100 || ! is_array($action['request'] ?? null)) {
                    throw ValidationException::withMessages(['actions' => 'Invalid action key or request.']);
                }
                $request = $action['request'];
                $request['site_id'] = $siteId;
                DB::table('durable_ai_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'site_id' => $siteId, 'batch_id' => $id,
                    'item_key' => $itemKey, 'position' => $position, 'request_hash' => $this->hash($request),
                    'state' => 'pending', 'request' => Crypt::encryptString(json_encode($request, JSON_THROW_ON_ERROR)),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });

        return $this->status($id);
    }

    public function resultOrDefer(array $request): array
    {
        if (! config('cpanel.ai_enabled')) {
            throw new AiPlatformException(AiFailureKind::ModelUnavailable, 'AI is disabled until configured and validated.', false, 503);
        }
        $batch = $this->submit([['key' => 'generation', 'request' => $request]], isset($request['site_id']) ? (int) $request['site_id'] : null);
        if ($batch['state'] === 'completed') {
            return $batch['items'][0]['result'];
        }
        throw new DeferredGeneration($batch);
    }

    public function status(string $id): array
    {
        app(TenantAuthorizer::class)->authorize('ai.use');
        $batch = $this->ownedBatch($id);
        $items = DB::table('durable_ai_items')->where('batch_id', $id)->where('tenant_id', $this->context->id())
            ->orderBy('position')->get()->map(fn ($item): array => [
                'key' => $item->item_key, 'state' => $item->state, 'attempts' => $item->attempts,
                'correlation_id' => $item->attempt_id, 'error_code' => $item->error_code,
                'result' => $item->state === 'completed' ? json_decode(Crypt::decryptString($item->result), true, 512, JSON_THROW_ON_ERROR) : null,
                'usage' => $item->usage ? json_decode($item->usage, true) : null, 'completed_at' => $item->completed_at,
            ])->all();

        return [
            'id' => $id, 'state' => $batch->state, 'site_id' => $batch->site_id,
            'completed' => count(array_filter($items, fn ($item) => $item['state'] === 'completed')),
            'total' => count($items), 'items' => $items, 'resume_after' => $batch->resume_after,
            'ai_enabled' => (bool) config('cpanel.ai_enabled'), 'cron_enabled' => (bool) config('cpanel.cron_enabled'),
            'status_url' => '/api/tenants/'.$this->context->tenant()->slug.'/ai-batches/'.$id,
        ];
    }

    public function control(string $id, string $action): array
    {
        app(TenantAuthorizer::class)->authorize('ai.use');
        DB::transaction(function () use ($id, $action): void {
            $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            $batch = $this->ownedBatch($id);
            abort_if($batch->state === 'completed', 409, 'Completed batches are immutable.');
            if ($action === 'resume') {
                abort_if($batch->state === 'failed', 409, 'This batch requires corrected input and a new submission.');
                abort_if($batch->cancelled_at !== null, 409, 'Cancelled batches cannot resume.');
                abort_if($batch->resume_after && now()->lt($batch->resume_after), 409, 'Retry-After has not elapsed.');
                abort_if($gate->owner !== null || $gate->circuit === 'needs_reconciliation', 409, 'Execution requires reconciliation.');
                abort_if(DB::table('durable_ai_items')->where('batch_id', $id)->where('state', 'needs_reconciliation')->exists(), 409);
                DB::table('durable_ai_gate')->where('id', 1)->update(['circuit' => 'closed']);
                DB::table('durable_ai_items')->where('batch_id', $id)->where('state', 'paused')->update(['state' => 'pending', 'error_code' => null]);
                $state = 'pending';
            } else {
                $state = 'paused';
                DB::table('durable_ai_items')->where('batch_id', $id)->where('state', 'pending')->update(['state' => 'paused']);
            }
            DB::table('durable_ai_batches')->where('id', $id)->update([
                'state' => $state, 'cancelled_at' => $action === 'cancel' ? now() : $batch->cancelled_at, 'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record('ai.batch.'.$action, [], 'DurableAiBatch', $id);
        });

        return $this->status($id);
    }

    private function ownedBatch(string $id): object
    {
        $batch = DB::table('durable_ai_batches')->where('id', $id)->where('tenant_id', $this->context->id())->first();
        abort_unless($batch, 404);
        if ($batch->site_id !== null) {
            Site::query()->findOrFail($batch->site_id);
        }

        return $batch;
    }

    /** Process at most one action. No queues, retries, recursion or fan-out. */
    public function runOne(): string
    {
        if (! config('cpanel.enabled') || ! config('cpanel.ai_enabled') || ! config('cpanel.cron_enabled') || ! config('cpanel.preflight_passed')) {
            return 'disabled';
        }
        $claim = $this->claim();
        if (! is_array($claim)) {
            return $claim;
        }
        $previousTenant = $this->context->active() ? $this->context->tenant() : null;
        $previousMembership = null;
        if ($previousTenant !== null) {
            try {
                $previousMembership = $this->context->membership();
            } catch (\LogicException) {
                // CLI normally has no authenticated context.
            }
        }
        try {
            $batch = DB::table('durable_ai_batches')->where('id', $claim['batch_id'])->first();
            $tenant = Tenant::query()->findOrFail($batch->tenant_id);
            $this->context->activate($tenant);
            $membership = TenantMembership::query()->where('user_id', $batch->user_id)->where('status', 'active')->firstOrFail();
            $this->context->activate($tenant, $membership);
            app(TenantAuthorizer::class)->authorize('ai.use');
            if ($batch->site_id !== null) {
                Site::query()->findOrFail($batch->site_id);
            }
            $request = json_decode(Crypt::decryptString($claim['request']), true, 512, JSON_THROW_ON_ERROR);
            if ($claim['provider'] !== null) {
                $request['_provider_key'] = $claim['provider'];
                $request['model'] = $claim['model'];
            }
            $this->permit->enter($claim);
            if (isset($request['_legacy_suggestion_id'])) {
                $job = new GenerateSuggestionJob($batch->tenant_id, (int) $request['_legacy_suggestion_id']);
                app()->call([$job, 'handle']);
                $suggestion = Suggestion::query()->findOrFail($request['_legacy_suggestion_id']);
                if ($suggestion->status !== 'ready') {
                    throw new \RuntimeException('Suggestion generation did not complete.');
                }
                $result = ['correlation_id' => $claim['owner'], 'provider' => 'legacy', 'model' => 'configured', 'structured' => $suggestion->proposed_state];
            } else {
                $result = app(AiGenerator::class)->generate($request);
            }
            DB::transaction(function () use ($claim, $result): void {
                $this->assertFence($claim);
                $record = AiGenerationRecord::query()->where('correlation_id', $result['correlation_id'])->first();
                $usage = AiUsageRecord::query()->where('correlation_id', $result['correlation_id'])->where('status', 'succeeded')->first();
                $changed = DB::table('durable_ai_items')->where('id', $claim['item_id'])->where('attempt_id', $claim['owner'])
                    ->where('state', 'running')->update([
                        'state' => 'completed', 'result' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)),
                        'provider' => $result['provider'], 'model' => $result['model'],
                        'usage' => json_encode(['generation_record_id' => $record?->id, 'correlation_id' => $result['correlation_id'], 'input_units' => $usage?->input_units, 'output_units' => $usage?->output_units, 'estimated_cost' => $usage?->estimated_cost, 'provider_request_id' => $usage?->provider_request_id], JSON_THROW_ON_ERROR),
                        'completed_at' => now(), 'updated_at' => now(),
                    ]);
                if ($changed !== 1) {
                    throw new \RuntimeException('Result fencing failed.');
                }
                app(AuditLogger::class)->record('ai.batch.item_completed', ['attempt_id' => $claim['owner']], 'DurableAiBatch', $claim['batch_id']);
                $remaining = DB::table('durable_ai_items')->where('batch_id', $claim['batch_id'])->where('state', '!=', 'completed')->exists();
                if (! $remaining) {
                    DB::table('durable_ai_batches')->where('id', $claim['batch_id'])->update(['state' => 'completed', 'updated_at' => now()]);
                }
                $this->releaseGate();
            });

            return 'completed';
        } catch (Throwable $exception) {
            $this->fail($claim, $exception);

            return 'paused';
        } finally {
            $this->permit->leave();
            $this->context->forget();
            if ($previousTenant !== null) {
                $this->context->activate($previousTenant, $previousMembership);
            }
        }
    }

    private function claim(): array|string
    {
        return DB::transaction(function (): array|string {
            $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            if ($gate->owner !== null) {
                if ($gate->expires_at && now()->gte($gate->expires_at)) {
                    DB::table('durable_ai_items')->where('id', $gate->item_id)->where('state', 'running')
                        ->update(['state' => 'needs_reconciliation', 'error_code' => 'worker_lease_expired', 'updated_at' => now()]);
                    DB::table('durable_ai_gate')->where('id', 1)->update(['circuit' => 'needs_reconciliation']);
                    // Do NOT steal an expired lease. The previous process may still
                    // be calling a provider. Operator reconciliation is mandatory.
                }

                return 'busy';
            }
            if ($gate->circuit !== 'closed' || ($gate->next_at && now()->lt($gate->next_at))) {
                return 'paused';
            }
            $item = DB::table('durable_ai_items as i')->join('durable_ai_batches as b', 'b.id', '=', 'i.batch_id')
                ->where('i.state', 'pending')->where('b.state', 'pending')->whereNull('b.cancelled_at')
                ->orderBy('i.created_at')->orderBy('i.position')->select('i.*')->first();
            if (! $item) {
                return 'idle';
            }
            $request = json_decode(Crypt::decryptString($item->request), true, 512, JSON_THROW_ON_ERROR);
            $tokens = max(1, strlen(json_encode($request, JSON_THROW_ON_ERROR)) + 4096) + min(8000, max(1, (int) ($request['max_output_tokens'] ?? 1500)));
            $today = now('UTC')->toDateString();
            $calls = $gate->day === $today ? (int) $gate->calls_reserved : 0;
            $reservedTokens = $gate->day === $today ? (int) $gate->tokens_reserved : 0;
            $usd = $gate->day === $today ? (float) $gate->usd_reserved : 0;
            $reserve = (float) config('cpanel.call_reserve_usd');
            $batchCalls = (int) DB::table('durable_ai_items')->where('batch_id', $item->batch_id)->sum('attempts');
            if (($batchCalls + 1) * $reserve > (float) config('cpanel.batch_budget_usd')) {
                DB::table('durable_ai_batches')->where('id', $item->batch_id)->update(['state' => 'paused']);
                DB::table('durable_ai_items')->where('id', $item->id)->update(['state' => 'paused', 'error_code' => 'batch_budget_exhausted']);

                return 'batch_budget_exhausted';
            }
            if ($reserve <= 0 || $calls + 1 > config('cpanel.daily_calls') || $reservedTokens + $tokens > config('cpanel.daily_tokens') || $usd + $reserve > config('cpanel.daily_budget_usd')) {
                DB::table('durable_ai_gate')->where('id', 1)->update(['circuit' => 'budget_exhausted']);
                DB::table('durable_ai_batches')->where('id', $item->batch_id)->update(['state' => 'paused']);

                return 'budget_exhausted';
            }
            $owner = (string) Str::uuid();
            $fence = (int) $gate->fence + 1;
            DB::table('durable_ai_gate')->where('id', 1)->update([
                'owner' => $owner, 'item_id' => $item->id, 'fence' => $fence,
                'expires_at' => now()->addSeconds(config('cpanel.lease_seconds')),
                'day' => $today, 'calls_reserved' => $calls + 1, 'tokens_reserved' => $reservedTokens + $tokens, 'usd_reserved' => $usd + $reserve,
            ]);
            DB::table('durable_ai_items')->where('id', $item->id)->where('state', 'pending')
                ->update(['state' => 'running', 'attempt_id' => $owner, 'attempts' => $item->attempts + 1, 'updated_at' => now()]);

            return ['owner' => $owner, 'fence' => $fence, 'item_id' => $item->id, 'batch_id' => $item->batch_id, 'request' => $item->request, 'provider' => $item->provider, 'model' => $item->model];
        });
    }

    private function assertFence(array $claim): void
    {
        $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
        if ($gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence'] || $gate->circuit !== 'closed' || now()->gte($gate->expires_at)) {
            throw new \RuntimeException('AI result lease lost.');
        }
    }

    private function fail(array $claim, Throwable $error): void
    {
        DB::transaction(function () use ($claim, $error): void {
            $gate = DB::table('durable_ai_gate')->where('id', 1)->lockForUpdate()->first();
            if ($gate->owner !== $claim['owner'] || (int) $gate->fence !== $claim['fence']) {
                return;
            }
            $item = DB::table('durable_ai_items')->where('id', $claim['item_id'])->first();
            $safeRejection = $error instanceof AiPlatformException && in_array($error->kind, [AiFailureKind::RateLimit, AiFailureKind::QuotaExceeded, AiFailureKind::InvalidCredentials, AiFailureKind::ModelUnavailable, AiFailureKind::QuotaBackendUnavailable, AiFailureKind::PolicyRejection], true);
            $ambiguous = $item->sent_at !== null && ! $safeRejection;
            $permanent = ! $ambiguous && ($error instanceof ValidationException || $error instanceof AuthorizationException || ($error instanceof AiPlatformException && $error->kind === AiFailureKind::PolicyRejection));
            $state = $ambiguous ? 'needs_reconciliation' : ($permanent ? 'failed' : 'paused');
            $code = $error instanceof AiPlatformException ? $error->kind->value : 'execution_interrupted';
            DB::table('durable_ai_items')->where('id', $item->id)->where('state', 'running')
                ->update(['state' => $state, 'error_code' => $code, 'updated_at' => now()]);
            DB::table('durable_ai_batches')->where('id', $claim['batch_id'])->update([
                'state' => $permanent ? 'failed' : 'paused', 'resume_after' => now()->addSeconds(max(60, $error instanceof AiPlatformException ? ($error->retryAfterSeconds ?? 60) : 60)), 'updated_at' => now(),
            ]);
            if ($ambiguous) {
                DB::table('durable_ai_gate')->where('id', 1)->update(['circuit' => 'needs_reconciliation']);
            } else {
                $this->releaseGate($permanent ? 'closed' : 'paused');
            }
        });
    }

    private function releaseGate(string $circuit = 'closed'): void
    {
        DB::table('durable_ai_gate')->where('id', 1)->update([
            'owner' => null, 'item_id' => null, 'expires_at' => null,
            'next_at' => now()->addSeconds(config('cpanel.min_interval')), 'circuit' => $circuit,
        ]);
    }

    private function hash(array $input): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
