<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>SEO Workspace</title>
    @vite(['resources/css/app.css'])
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
