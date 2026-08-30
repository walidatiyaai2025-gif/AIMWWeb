<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site Settings · {{ $site->name }}</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #0f172a; color: #e2e8f0; }
        .page { min-height: 100vh; box-sizing: border-box; padding: 40px 24px; }
        .shell { max-width: 900px; margin: 0 auto; }
        .kicker { display: block; margin-bottom: 8px; color: #60a5fa; font-size: 12px; font-weight: 900; letter-spacing: .16em; }
        h1 { margin: 0; font-size: clamp(30px, 5vw, 48px); }
        .subtitle { margin: 10px 0 28px; color: #94a3b8; }
        .panel { border: 1px solid rgba(148, 163, 184, .25); border-radius: 18px; background: rgba(255, 255, 255, .04); padding: 24px; box-shadow: 0 18px 50px rgba(15, 23, 42, .28); }
        dl { display: grid; gap: 16px; margin: 0; }
        dl > div { display: grid; grid-template-columns: minmax(130px, 180px) 1fr; gap: 16px; padding-bottom: 14px; border-bottom: 1px solid rgba(148, 163, 184, .16); }
        dl > div:last-child { border-bottom: 0; padding-bottom: 0; }
        dt { color: #94a3b8; font-weight: 700; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .notice { margin-top: 18px; padding: 16px; border-radius: 12px; background: rgba(59, 130, 246, .1); color: #bfdbfe; line-height: 1.6; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 22px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border-radius: 10px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; }
        .btn.secondary { background: rgba(148, 163, 184, .16); color: #e2e8f0; }
        .btn:focus-visible { outline: 3px solid #93c5fd; outline-offset: 3px; }
    </style>
</head>
<body>
    <main class="page" role="main" data-canonical-operation="AIMW-SITE-9F9F2977B5" aria-labelledby="site-settings-title">
        <div class="shell">
            <span class="kicker">SITE ADMINISTRATION</span>
            <h1 id="site-settings-title">Site Settings</h1>
            <p class="subtitle">{{ $site->name }} · tenant-scoped settings landing</p>

            <section class="panel" aria-label="Authoritative site settings snapshot">
                <dl>
                    <div><dt>Site name</dt><dd>{{ $site->name }}</dd></div>
                    <div><dt>Site URL</dt><dd>{{ $site->url }}</dd></div>
                    <div><dt>Operational status</dt><dd>{{ $site->status }}</dd></div>
                    <div><dt>Tenant</dt><dd>{{ $tenant->name }}</dd></div>
                </dl>

                <p class="notice">
                    This navigation opens the real tenant-scoped Site Settings surface. Profile changes, WordPress credentials,
                    operational-state changes, and deletion remain separate governed operations; this landing page does not
                    execute or simulate any of those mutations.
                </p>

                <nav class="actions" aria-label="Site settings navigation">
                    <a class="btn" href="{{ route('canonical.site.details', ['tenant' => $tenant->slug, 'site' => $site->getKey()]) }}">Site dashboard</a>
                    <a class="btn secondary" href="{{ route('canonical.workspace.sites', ['tenant' => $tenant->slug]) }}">Back to sites</a>
                </nav>
            </section>
        </div>
    </main>
</body>
</html>
