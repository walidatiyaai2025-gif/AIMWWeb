<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Contracts\AiQuotaGateway;
use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\AI\Platform\Support\AiSafetyPolicy;
use App\AI\Platform\Support\StructuredOutputValidator;
use App\Models\AiGenerationRecord;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Throwable;

final class AiGenerationService implements AiGenerator
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AiQuotaGateway $quota,
        private readonly ModelCatalogService $models,
        private readonly PromptRegistryService $prompts,
        private readonly ProviderRegistry $providers,
        private readonly ProviderSecretStore $secrets,
        private readonly StructuredOutputValidator $structured,
        private readonly AiSafetyPolicy $safety,
        private readonly AiUsageService $usage,
        private readonly AuditLogger $audit,
    ) {}

    public function generate(array $request): array
    {
        $workflow = trim((string) ($request['workflow'] ?? ''));
        if ($workflow === '') {
            throw new AiPlatformException(AiFailureKind::PolicyRejection, 'AI workflow is required.', false, 422);
        }

        $userId = $this->context->membership()->user_id;
        $tenantId = $this->context->id();
        $correlationId = (string) Str::uuid();
        $startedAt = now();
        $resolved = $this->resolvePrompt($request);
        $system = $this->safety->sanitizePrompt($resolved['system']);
        $user = $this->safety->sanitizePrompt($resolved['user']);
        if ($user === '') {
            throw new AiPlatformException(AiFailureKind::PolicyRejection, 'AI prompt is required.', false, 422);
        }

        $schema = $resolved['output_schema'];
        $requestHash = hash('sha256', json_encode([
            'workflow' => $workflow,
            'prompt_key' => $resolved['template']?->stable_key,
            'prompt_version' => $resolved['template']?->current_version,
            'system' => $system,
            'user' => $user,
            'schema' => $schema,
            'site_id' => $request['site_id'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $generation = AiGenerationRecord::query()->create([
            'user_id' => $userId,
            'ai_prompt_template_id' => $resolved['template']?->id,
            'prompt_version' => $resolved['template']?->current_version,
            'workflow' => $workflow,
            'request_hash' => $requestHash,
            'correlation_id' => $correlationId,
            'status' => 'running',
            'retry_count' => 0,
            'started_at' => $startedAt,
            'created_at' => $startedAt,
        ]);

        $quota = $this->quota->check($tenantId, $userId, $workflow, 1);
        if (! ($quota['allowed'] ?? false)) {
            $kind = ($quota['code'] ?? '') === 'quota_backend_unavailable'
                ? AiFailureKind::QuotaBackendUnavailable
                : AiFailureKind::QuotaExceeded;
            $this->failGeneration($generation, $kind, null, null, 0);
            $this->audit->record('ai.generation.quota_rejected', [
                'workflow' => $workflow,
                'correlation_id' => $correlationId,
                'code' => (string) ($quota['code'] ?? 'quota_exceeded'),
                'limit' => $quota['limit'] ?? null,
                'current' => $quota['current'] ?? null,
            ], 'AiGenerationRecord', $generation->id);

            throw new AiPlatformException(
                $kind,
                $this->safety->sanitizeError((string) ($quota['message'] ?? 'AI quota rejected the request.')),
                false,
                $kind === AiFailureKind::QuotaExceeded ? 429 : 503,
            );
        }

        $requiredCapabilities = ['text_generation'];
        if (is_array($schema)) {
            $requiredCapabilities[] = 'structured_json';
        }

        $candidates = $this->models->candidates($requiredCapabilities, $request['model'] ?? null);
        if ($candidates === []) {
            $this->failGeneration($generation, AiFailureKind::ModelUnavailable, null, null, 0);
            throw new AiPlatformException(
                AiFailureKind::ModelUnavailable,
                'No READY provider/model satisfies the requested AI capabilities.',
                false,
                503,
            );
        }

        $totalRetries = 0;
        $lastException = null;

        foreach ($candidates as $candidateIndex => $candidate) {
            /** @var AiProviderProfile $provider */
            $provider = $candidate['provider'];
            /** @var AiModelProfile $model */
            $model = $candidate['model'];
            $client = $this->providers->get($provider->adapter_key);
            $apiKey = $this->secrets->get($provider);
            $attempts = min(max((int) $provider->max_attempts, 1), 3);

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $attemptStarted = microtime(true);

                try {
                    $providerResponse = $client->generate($provider, $model, $apiKey, [
                        'system' => $system ?: null,
                        'user' => $user,
                        'temperature' => min(max((float) ($request['temperature'] ?? 0.2), 0.0), 2.0),
                        'max_output_tokens' => min(max((int) ($request['max_output_tokens'] ?? 1500), 1), 8000),
                        'output_schema' => $schema,
                    ]);
                    $latency = (int) round((microtime(true) - $attemptStarted) * 1000);
                    $structured = is_array($schema)
                        ? $this->structured->decodeAndValidate($providerResponse['content'], $schema)
                        : null;
                    $estimatedCost = $this->estimateCost(
                        $model,
                        (int) $providerResponse['input_units'],
                        (int) $providerResponse['output_units'],
                    );

                    $this->usage->record([
                        'user_id' => $userId,
                        'ai_provider_profile_id' => $provider->id,
                        'provider_key' => $provider->provider_key,
                        'model_key' => $model->model_key,
                        'workflow' => $workflow,
                        'input_units' => (int) $providerResponse['input_units'],
                        'output_units' => (int) $providerResponse['output_units'],
                        'estimated_cost' => $estimatedCost,
                        'actual_cost' => $providerResponse['actual_cost'],
                        'currency' => $model->currency ?: 'USD',
                        'status' => 'succeeded',
                        'failure_kind' => null,
                        'latency_ms' => $latency,
                        'retry_count' => $totalRetries,
                        'correlation_id' => $correlationId,
                        'provider_request_id' => $providerResponse['provider_request_id'],
                        'metadata' => ['site_id' => $request['site_id'] ?? null],
                    ]);

                    $generation->update([
                        'provider_key' => $provider->provider_key,
                        'model_key' => $model->model_key,
                        'status' => 'succeeded',
                        'failure_kind' => null,
                        'structured_output' => $structured,
                        'retry_count' => $totalRetries,
                        'completed_at' => now(),
                    ]);
                    $this->audit->record('ai.generation.succeeded', [
                        'workflow' => $workflow,
                        'provider_key' => $provider->provider_key,
                        'model_key' => $model->model_key,
                        'correlation_id' => $correlationId,
                        'retry_count' => $totalRetries,
                    ], 'AiGenerationRecord', $generation->id);

                    return [
                        'correlation_id' => $correlationId,
                        'provider' => $provider->provider_key,
                        'model' => $model->model_key,
                        'content' => $providerResponse['content'],
                        'structured' => $structured,
                    ];
                } catch (AiPlatformException $exception) {
                    $lastException = $exception;
                    $latency = (int) round((microtime(true) - $attemptStarted) * 1000);
                    $this->recordFailureAttempt(
                        $provider,
                        $model,
                        $workflow,
                        $correlationId,
                        $userId,
                        $latency,
                        $totalRetries,
                        $exception,
                        $request['site_id'] ?? null,
                    );

                    if (! $exception->retryable || ! $exception->kind->retryable()) {
                        $this->failGeneration(
                            $generation,
                            $exception->kind,
                            $provider->provider_key,
                            $model->model_key,
                            $totalRetries,
                        );
                        $this->auditFailure($generation, $workflow, $correlationId, $provider, $model, $exception, $totalRetries);

                        throw $exception;
                    }

                    $canRetrySameProvider = $attempt < $attempts
                        && ! ($exception->kind === AiFailureKind::RateLimit
                            && ($exception->retryAfterSeconds ?? 0) > 5);
                    if ($canRetrySameProvider) {
                        $totalRetries++;

                        continue;
                    }

                    if (! $provider->automatic_failover || $candidateIndex === array_key_last($candidates)) {
                        $this->failGeneration(
                            $generation,
                            $exception->kind,
                            $provider->provider_key,
                            $model->model_key,
                            $totalRetries,
                        );
                        $this->auditFailure($generation, $workflow, $correlationId, $provider, $model, $exception, $totalRetries);

                        throw $exception;
                    }

                    $totalRetries++;
                    break;
                } catch (Throwable $exception) {
                    $safe = new AiPlatformException(
                        AiFailureKind::Unknown,
                        $this->safety->sanitizeError($exception->getMessage()) ?: 'AI provider failed unexpectedly.',
                        false,
                        502,
                    );
                    $this->failGeneration(
                        $generation,
                        $safe->kind,
                        $provider->provider_key,
                        $model->model_key,
                        $totalRetries,
                    );
                    $this->auditFailure($generation, $workflow, $correlationId, $provider, $model, $safe, $totalRetries);

                    throw $safe;
                }
            }
        }

        $lastException ??= new AiPlatformException(
            AiFailureKind::ProviderUnavailable,
            'No AI provider completed the request.',
            false,
            503,
        );
        $this->failGeneration($generation, $lastException->kind, null, null, $totalRetries);

        throw $lastException;
    }

    private function resolvePrompt(array $request): array
    {
        if (filled($request['prompt_key'] ?? null)) {
            $rendered = $this->prompts->render((string) $request['prompt_key'], (array) ($request['variables'] ?? []));
            if (isset($request['output_schema']) && $rendered['output_schema'] === null) {
                $rendered['output_schema'] = $request['output_schema'];
            }

            return $rendered;
        }

        return [
            'template' => null,
            'system' => $request['system_prompt'] ?? null,
            'user' => (string) ($request['user_prompt'] ?? ''),
            'output_schema' => $request['output_schema'] ?? null,
        ];
    }

    private function estimateCost(AiModelProfile $model, int $inputUnits, int $outputUnits): float
    {
        $inputRate = (float) ($model->input_cost_per_million ?? 0);
        $outputRate = (float) ($model->output_cost_per_million ?? 0);

        return round(($inputUnits / 1000000 * $inputRate) + ($outputUnits / 1000000 * $outputRate), 6);
    }

    private function recordFailureAttempt(
        AiProviderProfile $provider,
        AiModelProfile $model,
        string $workflow,
        string $correlationId,
        int $userId,
        int $latency,
        int $retryCount,
        AiPlatformException $exception,
        ?int $siteId,
    ): void {
        $this->usage->record([
            'user_id' => $userId,
            'ai_provider_profile_id' => $provider->id,
            'provider_key' => $provider->provider_key,
            'model_key' => $model->model_key,
            'workflow' => $workflow,
            'input_units' => 0,
            'output_units' => 0,
            'estimated_cost' => 0,
            'actual_cost' => null,
            'currency' => $model->currency ?: 'USD',
            'status' => 'failed',
            'failure_kind' => $exception->kind->value,
            'latency_ms' => $latency,
            'retry_count' => $retryCount,
            'correlation_id' => $correlationId,
            'provider_request_id' => null,
            'metadata' => [
                'site_id' => $siteId,
                'error' => $this->safety->sanitizeError($exception->getMessage()),
            ],
        ]);
    }

    private function failGeneration(
        AiGenerationRecord $generation,
        AiFailureKind $kind,
        ?string $providerKey,
        ?string $modelKey,
        int $retryCount,
    ): void {
        $generation->update([
            'provider_key' => $providerKey,
            'model_key' => $modelKey,
            'status' => 'failed',
            'failure_kind' => $kind->value,
            'retry_count' => $retryCount,
            'completed_at' => now(),
        ]);
    }

    private function auditFailure(
        AiGenerationRecord $generation,
        string $workflow,
        string $correlationId,
        AiProviderProfile $provider,
        AiModelProfile $model,
        AiPlatformException $exception,
        int $retryCount,
    ): void {
        $this->audit->record('ai.generation.failed', [
            'workflow' => $workflow,
            'provider_key' => $provider->provider_key,
            'model_key' => $model->model_key,
            'correlation_id' => $correlationId,
            'failure_kind' => $exception->kind->value,
            'retry_count' => $retryCount,
        ], 'AiGenerationRecord', $generation->id);
    }
}
