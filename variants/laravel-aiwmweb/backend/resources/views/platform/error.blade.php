<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unexpected error</title>
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
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
        .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border-radius: 10px; background: #2563eb; color: #fff; font-weight: 700; text-decoration: none; }
        .button.secondary { background: #1e293b; border: 1px solid rgba(148, 163, 184, .25); }
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
                @if ($logsHref !== null)
                    <a class="button secondary" href="{{ $logsHref }}" data-canonical-operation="AIMW-CONT-8B3518EF80">Open logs</a>
                @endif
                <a class="button" href="/" data-canonical-operation="AIMW-CONT-85394A0E55">Back to dashboard</a>
            </div>
        </section>
    </main>
</body>
</html>
