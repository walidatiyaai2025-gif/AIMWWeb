<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>AI WordPress Manager</title>
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at top, #16233d 0, #08111f 48%, #050a12 100%); color: #eef4ff; }
        .shell { width: min(1120px, calc(100% - 40px)); margin: 0 auto; }
        .header { min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 24px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .brand { display: inline-flex; align-items: center; gap: 12px; color: inherit; text-decoration: none; font-weight: 800; letter-spacing: -.02em; }
        .brand-mark { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 12px; background: linear-gradient(135deg, #5eead4, #60a5fa); color: #06111e; box-shadow: 0 12px 40px rgba(96,165,250,.25); }
        .brand-copy { display: grid; gap: 2px; }
        .brand-copy small { color: #91a4bf; font-size: 11px; letter-spacing: .12em; font-weight: 700; }
        .signin { color: #d9e7fb; text-decoration: none; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 10px 15px; font-weight: 700; }
        .hero { min-height: calc(100vh - 77px); display: grid; grid-template-columns: 1.15fr .85fr; align-items: center; gap: 56px; padding: 72px 0; }
        .eyebrow { color: #72e2d1; font-size: 12px; font-weight: 800; letter-spacing: .16em; }
        h1 { margin: 16px 0 20px; max-width: 760px; font-size: clamp(42px, 6vw, 78px); line-height: .98; letter-spacing: -.055em; }
        .lead { margin: 0; max-width: 700px; color: #aabbd1; font-size: clamp(17px, 2vw, 21px); line-height: 1.7; }
        .proof { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 12px; color: #c9d8ec; }
        .proof span { padding: 10px 13px; border: 1px solid rgba(255,255,255,.1); border-radius: 999px; background: rgba(255,255,255,.035); }
        .panel { padding: 28px; border: 1px solid rgba(255,255,255,.1); border-radius: 24px; background: linear-gradient(180deg, rgba(20,35,59,.92), rgba(8,18,32,.92)); box-shadow: 0 30px 80px rgba(0,0,0,.32); }
        .panel-kicker { color: #91a4bf; font-size: 11px; font-weight: 800; letter-spacing: .14em; }
        .panel h2 { margin: 12px 0 18px; font-size: 24px; }
        .rows { display: grid; gap: 10px; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 14px 15px; border-radius: 14px; background: rgba(255,255,255,.045); }
        .row b { color: #72e2d1; }
        @media (max-width: 820px) { .hero { grid-template-columns: 1fr; padding: 48px 0; } .panel { order: -1; } }
    </style>
</head>
<body>
    <div class="shell">
        <header class="header">
            <a
                class="brand"
                href="{{ route('public.welcome', [], false) }}"
                data-canonical-operation="AIMW-AI-4C07560F0B"
                aria-label="AI WordPress Manager"
            >
                <span class="brand-mark" aria-hidden="true">AI</span>
                <span class="brand-copy">
                    <strong>AI WordPress Manager</strong>
                    <small>WORDPRESS OPERATIONS OS</small>
                </span>
            </a>
            <a class="signin" href="/login">Sign in</a>
        </header>

        <main class="hero">
            <section>
                <div class="eyebrow">AI-ASSISTED WORDPRESS OPERATIONS</div>
                <h1>Operate WordPress as one system.</h1>
                <p class="lead">A public, identity-neutral entry point for AI WordPress Manager. Sign in to reach tenant-owned sites, operations, content, SEO and automation through their protected application routes.</p>
                <div class="proof" aria-label="Platform boundaries">
                    <span>Public welcome</span>
                    <span>Protected tenant workspace</span>
                    <span>Auditable operations</span>
                </div>
            </section>

            <aside class="panel" aria-label="Workspace preview">
                <div class="panel-kicker">OPERATIONS OVERVIEW</div>
                <h2>One operating layer</h2>
                <div class="rows">
                    <div class="row"><span>Sites</span><b>Connected</b></div>
                    <div class="row"><span>Content</span><b>Managed</b></div>
                    <div class="row"><span>SEO</span><b>Observed</b></div>
                    <div class="row"><span>AI actions</span><b>Governed</b></div>
                </div>
            </aside>
        </main>
    </div>
</body>
</html>
