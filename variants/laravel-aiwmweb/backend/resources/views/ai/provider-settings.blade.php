<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Provider Settings</title>
</head>
<body>
    <main data-canonical-operation="AIMW-AI-58FABCCEDB">
        <header>
            <p>AI PROVIDER REGISTRY</p>
            <h1>AI Provider Settings</h1>
            <p>Review persisted provider configuration and runtime readiness for {{ $tenant->name }}.</p>
            <p>Settings managers only. API credentials are never rendered; only credential presence is exposed.</p>
        </header>

        <section aria-labelledby="provider-registry">
            <h2 id="provider-registry">Provider registry</h2>
            <p>{{ count($providers) }} configured {{ count($providers) === 1 ? 'provider' : 'providers' }}</p>

            @forelse ($providers as $provider)
                <article data-provider-key="{{ $provider['provider_key'] }}">
                    <header>
                        <h3>{{ $provider['display_name'] }}</h3>
                        <p>{{ $provider['provider_key'] }} · {{ $provider['enabled'] ? 'Enabled' : 'Disabled' }}</p>
                    </header>
                    <dl>
                        <dt>Adapter</dt><dd>{{ $provider['adapter_key'] }}</dd>
                        <dt>Endpoint</dt><dd>{{ $provider['endpoint'] ?: 'Default provider endpoint' }}</dd>
                        <dt>Default model</dt><dd>{{ $provider['default_model'] ?: 'Not configured' }}</dd>
                        <dt>Priority</dt><dd>{{ $provider['priority'] }}</dd>
                        <dt>Timeout</dt><dd>{{ $provider['timeout_seconds'] }} seconds</dd>
                        <dt>Maximum attempts</dt><dd>{{ $provider['max_attempts'] }}</dd>
                        <dt>Automatic failover</dt><dd>{{ $provider['automatic_failover'] ? 'Enabled' : 'Disabled' }}</dd>
                        <dt>Readiness</dt><dd>{{ $provider['readiness'] }}</dd>
                        <dt>Readiness checked</dt><dd>{{ $provider['readiness_checked_at'] ?: 'Not checked' }}</dd>
                        <dt>Readiness error</dt><dd>{{ $provider['readiness_error'] ?: 'None' }}</dd>
                        <dt>API credential</dt><dd>{{ $provider['has_api_key'] ? 'Configured' : 'Not configured' }}</dd>
                    </dl>

                    <section aria-label="Models for {{ $provider['provider_key'] }}">
                        <h4>Models</h4>
                        @forelse ($provider['models'] as $model)
                            <article data-model-key="{{ $model['model_key'] }}">
                                <strong>{{ $model['display_name'] }}</strong>
                                <span> · {{ $model['enabled'] ? 'Enabled' : 'Disabled' }}</span>
                            </article>
                        @empty
                            <p>No persisted model profiles.</p>
                        @endforelse
                    </section>
                </article>
            @empty
                <p role="status">No AI provider profiles have been persisted for this tenant.</p>
            @endforelse
        </section>
    </main>
</body>
</html>
