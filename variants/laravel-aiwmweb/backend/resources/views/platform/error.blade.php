@php
    $errorDetailsPayload = [
        'errorId' => $errorId,
        'correlationId' => $correlationId,
    ];
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unexpected error</title>
    @vite('resources/js/error-copy-details.ts')
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #0b0f17; color: #f8fafc; }
        .error-page { min-height: 100vh; display: grid; place-items: center; padding: 32px; box-sizing: border-box; }
        .error-card { width: min(760px, 100%); padding: 32px; box-sizing: border-box; border: 1px solid rgba(248, 113, 113, .3); border-radius: 18px; background: rgba(255, 255, 255, .04); box-shadow: 0 18px 50px rgba(0, 0, 0, .28); }
        .kicker { display: inline-block; color: #f87171; font-size: 12px; font-weight: 900; letter-spacing: .14em; }
        h1 { margin: 10px 0; font-size: clamp(28px, 4vw, 42px); }
        p { color: #cbd5e1; line-height: 1.65; }
        .diagnostics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 24px 0; }
        .diagnostic { padding: 14px; border: 1px solid rgba(148, 163, 184, .25); border-radius: 12px; background: rgba(15, 23, 42, .65); min-width: 0; }
        .diagnostic small { display: block; margin-bottom: 6px; color: #94a3b8; }
        .diagnostic strong { display: block; overflow-wrap: anywhere; }
        .note { padding: 14px; border-radius: 12px; background: rgba(59, 130, 246, .09); border: 1px solid rgba(96, 165, 250, .25); }
        .actions { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border: 0; border-radius: 10px; background: #2563eb; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #1e293b; border: 1px solid rgba(148, 163, 184, .25); }
        .button:disabled { cursor: wait; opacity: .65; }
        .copy-status { margin: 12px 0 0; }
        .copy-error { margin-top: 12px; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <main class="error-page" role="main" aria-labelledby="error-title" data-canonical-operation="AIMW-CONT-455F01DAC7">
        <section class="error-card">
            <span class="kicker">ERROR CENTER</span>
            <h1 id="error-title">An unexpected error occurred</h1>
            <p>The error was recorded. Use the tracking details below when reviewing logs or requesting support.</p>

            <h2>Tracking information</h2>
            <p>This screen does not expose sensitive technical details.</p>

            <div class="diagnostics">
                <div class="diagnostic"><small>Error ID</small><strong>{{ $errorId }}</strong></div>
                <div class="diagnostic"><small>Correlation ID</small><strong>{{ $correlationId }}</strong></div>
                <div class="diagnostic"><small>Error time</small><strong>{{ $errorTime }}</strong></div>
            </div>

            <div class="note">Use the Error ID or Correlation ID when reviewing server logs or requesting support.</div>

            <div class="actions">
                <button class="button" type="button" data-copy-error-details data-canonical-operation="AIMW-SYNC-89777052CB" aria-busy="false">Copy error details</button>
                @if ($logsHref !== null)
                    <a class="button secondary" href="{{ $logsHref }}" data-canonical-operation="AIMW-CONT-8B3518EF80">Open logs</a>
                @endif
                <a class="button" href="/" data-canonical-operation="AIMW-CONT-85394A0E55">Back to dashboard</a>
            </div>
            <p class="copy-status" data-copy-error-success role="status" hidden>Error details copied. The browser confirmed the clipboard write.</p>
            <div class="copy-error" data-copy-error-error role="alert" hidden>
                <p data-copy-error-error-message>The browser did not confirm a clipboard write. No copy success was reported; you can retry.</p>
                <button class="button" type="button" data-copy-error-retry>Retry copy</button>
            </div>

            <script id="error-details-payload" type="application/json">@json($errorDetailsPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
        </section>
    </main>
</body>
</html>
