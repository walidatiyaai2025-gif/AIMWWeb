<?php

namespace App\Http\Controllers;

use App\AI\Platform\Services\ModelCatalogService;
use App\AI\Platform\Services\ProviderConfigService;
use App\AI\Platform\Services\ProviderReadinessService;
use App\Authorization\TenantAuthorizer;
use App\Models\AiProviderProfile;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AiProviderManagementController extends Controller
{
    public function save(
        Request $request,
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        ProviderConfigService $providers,
        ModelCatalogService $models,
    ): RedirectResponse {
        abort_unless($context->tenant()->slug === $tenant, 404);
        $authorizer->authorize('settings.manage');

        $data = $request->validate([
            'provider_key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_-]{0,62}$/'],
            'display_name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'url', 'max:2048'],
            'model_key' => ['required', 'string', 'max:150'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'enabled' => ['sometimes', 'boolean'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:64', 'max:2048'],
        ]);
        $this->assertSafeHttpsEndpoint($data['endpoint']);

        DB::transaction(function () use ($data, $request, $providers, $models): void {
            $existing = AiProviderProfile::query()->where('provider_key', $data['provider_key'])->first();
            if ($existing && $existing->adapter_key !== 'openai-compatible') {
                throw ValidationException::withMessages([
                    'provider_key' => 'This provider uses another adapter. Choose a distinct provider key.',
                ]);
            }

            $configured = $providers->save($existing, [
                'provider_key' => $data['provider_key'],
                'adapter_key' => 'openai-compatible',
                'display_name' => $data['display_name'],
                'endpoint' => rtrim($data['endpoint'], '/'),
                'default_model' => $data['model_key'],
                'enabled' => $request->boolean('enabled'),
                'priority' => $existing?->priority ?? 10,
                // A single durable AI execution at a time, no automatic retry/failover.
                'timeout_seconds' => 30,
                'max_attempts' => 1,
                'automatic_failover' => false,
                ...(! empty($data['api_key']) ? ['api_key' => $data['api_key']] : []),
            ]);

            $model = $configured->models()->where('model_key', $data['model_key'])->first();
            $models->save($configured, $model, [
                'model_key' => $data['model_key'],
                'display_name' => $data['model_key'],
                'enabled' => true,
                'capabilities' => ['text_generation', 'structured_json'],
                'context_window' => 4096,
                'max_output_tokens' => (int) ($data['max_output_tokens'] ?? 512),
                'input_cost_per_million' => 0,
                'output_cost_per_million' => 0,
                'currency' => 'USD',
            ]);
        }, 3);

        return redirect()->route('tenant.settings.ai-providers', ['tenant' => $tenant])
            ->with('status', 'Provider saved securely. Use Check connection to confirm model readiness.');
    }

    public function check(
        string $tenant,
        string $provider,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        ProviderReadinessService $readiness,
    ): RedirectResponse {
        abort_unless($context->tenant()->slug === $tenant, 404);
        $authorizer->authorize('settings.manage');
        $profile = AiProviderProfile::query()->where('provider_key', $provider)->firstOrFail();
        $result = $readiness->check($profile);

        return redirect()->route('tenant.settings.ai-providers', ['tenant' => $tenant])
            ->with('status', 'Provider readiness: '.$result['state'].'. '.($result['message'] ?? ''));
    }

    private function assertSafeHttpsEndpoint(string $value): void
    {
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/(^|\\.)(localhost|local|internal|invalid|test)$/i', $host)
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw ValidationException::withMessages([
                'endpoint' => 'Use a trusted public HTTPS provider hostname, such as https://ai.walidatiya.com/v1. Private/loopback hosts are not allowed.',
            ]);
        }
        if (! str_ends_with(rtrim((string) ($parts['path'] ?? ''), '/'), '/v1')) {
            throw ValidationException::withMessages([
                'endpoint' => 'The OpenAI-compatible provider URL must end in /v1.',
            ]);
        }
    }
}
