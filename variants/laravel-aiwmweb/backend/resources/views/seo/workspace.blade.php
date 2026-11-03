<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>SEO Workspace</title>
    @vite(['resources/css/app.css'])
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
<main class="workspace-stack" data-canonical-operation="AIMW-SEO-4CBBC7AAD9">
    <section class="hero-panel">
        <div>
            <span class="workspace-kicker">SEO WORKSPACE</span>
            <h1>SEO Workspace</h1>
            <p>Open the real tenant SEO surfaces. No sample findings or synthetic status are rendered here.</p>
        </div>
        <a class="btn" href="{{ $links['sites'] }}">Select Site</a>
    </section>
    <section class="workspace-card-grid" aria-label="SEO workspace destinations">
        <a class="workspace-card" href="{{ $links['audit'] }}">
            <span class="workspace-card-icon" aria-hidden="true">⌁</span>
            <div><strong>SEO Audit</strong><p>Inspect persisted audit runs and authoritative findings.</p></div>
        </a>
        <a class="workspace-card" href="{{ $links['suggestions'] }}">
            <span class="workspace-card-icon" aria-hidden="true">✦</span>
            <div><strong>SEO Suggestions</strong><p>Review persisted remediation suggestions.</p></div>
        </a>
        <a class="workspace-card" href="{{ $links['approvals'] }}">
            <span class="workspace-card-icon" aria-hidden="true">✓</span>
            <div><strong>Approval Queue</strong><p>Approve or reject governed SEO changes before execution.</p></div>
        </a>
    </section>
</main>
</body>
</html>
