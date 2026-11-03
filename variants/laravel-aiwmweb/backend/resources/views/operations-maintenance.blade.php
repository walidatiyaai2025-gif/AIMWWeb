<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Operation History Maintenance — AI WordPress Manager</title>
    @vite(['resources/css/app.css'])
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than previous baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 62.5%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
        @media (min-width: 1140px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
    </style>
</head>
<body>
<main class="fatal-error"
      data-canonical-operation="AIMW-AI-6EF2330C99"
      data-record-count="{{ (int) $storage['record_count'] }}">
    <section class="panel">
        <span class="workspace-kicker">STORAGE MANAGEMENT</span>
        <h1>Site Operation History Maintenance</h1>
        <p>Review the real tenant-scoped operation-history footprint and the default retention preview. Mutating maintenance controls are governed as separate canonical operations.</p>
        <p>
            <a class="btn"
               data-canonical-operation="AIMW-AI-C2776A0F99"
               href="{{ route('canonical.workspace.site-operations', ['tenant' => $tenant], false) }}">Operation history</a>
        </p>
        @if ($canOpenOperationsHub)
            <p>
                <a class="btn primary"
                   data-canonical-operation="AIMW-AI-9E73ABE9CE"
                   href="{{ route('canonical.workspace.operations', ['tenant' => $tenant], false) }}">Operations hub</a>
            </p>
        @endif
    </section>

    <section class="panel" aria-label="Operation history storage">
        <h2>Current storage</h2>
        <dl>
            <dt>Total records</dt><dd data-testid="record-count">{{ (int) $storage['record_count'] }}</dd>
            <dt>Sites represented</dt><dd>{{ (int) $storage['site_count'] }}</dd>
            <dt>Oldest operation</dt><dd>{{ $storage['oldest_operation_at'] ?: '—' }}</dd>
            <dt>Newest operation</dt><dd>{{ $storage['newest_operation_at'] ?: '—' }}</dd>
            <dt>Storage</dt><dd>{{ $storage['storage'] }}</dd>
        </dl>
    </section>

    <section class="panel" aria-label="Default cleanup preview">
        <h2>Default retention preview</h2>
        <p>90-day cutoff while retaining the newest 100 tenant-scoped records.</p>
        <dl>
            <dt>Eligible for removal</dt><dd>{{ (int) $preview['removable_count'] }}</dd>
            <dt>Total in scope</dt><dd>{{ (int) $preview['total_count'] }}</dd>
            <dt>Keep latest</dt><dd>{{ (int) $preview['keep_latest'] }}</dd>
            <dt>Cutoff</dt><dd>{{ $preview['cutoff'] }}</dd>
        </dl>
    </section>
</main>
</body>
</html>