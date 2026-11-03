<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #0f172a; color: #e2e8f0; }
        .access-denied-page { min-height: 100vh; display: grid; place-items: center; padding: 32px; box-sizing: border-box; }
        .access-denied-card { max-width: 560px; width: 100%; padding: 32px; box-sizing: border-box; text-align: center; border: 1px solid rgba(148, 163, 184, .25); border-radius: 18px; background: rgba(255, 255, 255, .04); box-shadow: 0 18px 50px rgba(15, 23, 42, .28); }
        .access-denied-code { display: inline-block; margin-bottom: 10px; font-size: 6.5px; font-weight: 900; letter-spacing: .16em; color: #f87171; }
        h1 { margin: 0 0 10px; font-size: clamp(14px, 2vw, 21px); }
        p { margin: 0 auto 22px; max-width: 440px; line-height: 1.65; color: #94a3b8; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border-radius: 10px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; }
        .btn.secondary { background: transparent; border: 1px solid rgba(148, 163, 184, .45); }
        .btn:focus-visible { outline: 3px solid #93c5fd; outline-offset: 3px; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography scale 50%. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 50%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
    <main class="access-denied-page" role="main" aria-labelledby="access-denied-title">
        <section class="access-denied-card">
            <span class="access-denied-code">403</span>
            <h1 id="access-denied-title">Access denied</h1>
            <p>You are signed in, but your account does not have permission to open this page.</p>
            <div class="actions">
                <a class="btn primary" href="/" data-canonical-operation="AIMW-CONT-9D5E269773">Return home</a>
                @if (! empty($profileUrl))
                    <a class="btn secondary" href="{{ $profileUrl }}" data-canonical-operation="AIMW-IDEN-03760967D1">Open my account</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
