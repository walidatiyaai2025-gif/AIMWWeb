<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site operation details</title>
    @vite('resources/css/app.css')
</head>
<body>
    <main class="workspace-main" data-canonical-operation="AIMW-AI-3CDB30A4C2">
        <section class="hero-panel" aria-labelledby="site-operation-heading">
            <div>
                <span class="workspace-kicker">SITE OPERATION</span>
                <h1 id="site-operation-heading">Site operation details</h1>
                <p>Read-only execution history for the active tenant.</p>
            </div>
            <a class="btn" href="{{ $historyUrl }}">Back to site operations</a>
        </section>

        <section class="panel" aria-labelledby="operation-summary-heading">
            <h2 id="operation-summary-heading">Operation summary</h2>
            <dl class="contract-details">
                <div><dt>Result</dt><dd>{{ $operation->status ?: '—' }}</dd></div>
                <div><dt>Operation</dt><dd>{{ $operation->operation ?: '—' }}</dd></div>
                <div><dt>Site</dt><dd>{{ $site?->name ?: ('Site #'.(string) $operation->site_id) }}</dd></div>
                <div><dt>Started</dt><dd>{{ $operation->started_at?->toIso8601String() ?: '—' }}</dd></div>
                <div><dt>Completed</dt><dd>{{ $operation->completed_at?->toIso8601String() ?: '—' }}</dd></div>
                <div><dt>Duration</dt><dd>{{ $durationMs === null ? '—' : $durationMs.' ms' }}</dd></div>
                <div><dt>Affected records</dt><dd>{{ $operation->affected_records === null ? '—' : $operation->affected_records }}</dd></div>
                <div><dt>Correlation ID</dt><dd><code>{{ $operation->correlation_id }}</code></dd></div>
            </dl>
        </section>

        <section class="panel" aria-labelledby="operation-message-heading">
            <h2 id="operation-message-heading">Message</h2>
            <p>{{ $operation->message ?: 'No message was recorded.' }}</p>
        </section>

        <section class="panel" aria-labelledby="technical-details-heading">
            <h2 id="technical-details-heading">Technical details</h2>
            @if (! empty($operation->details))
                <pre>{{ json_encode($operation->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <p>No technical details were recorded.</p>
            @endif
        </section>
    </main>
</body>
</html>
