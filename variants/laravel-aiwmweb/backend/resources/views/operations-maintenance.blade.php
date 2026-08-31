<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Operation History Maintenance — AI WordPress Manager</title>
    @vite(['resources/css/app.css'])
</head>
<body>
<main class="fatal-error"
      data-canonical-operation="AIMW-AI-6EF2330C99"
      data-record-count="{{ (int) $storage['record_count'] }}">
    <section class="panel">
        <span class="workspace-kicker">STORAGE MANAGEMENT</span>
        <h1>Site Operation History Maintenance</h1>
        <p>Review the real tenant-scoped operation-history footprint and the default retention preview. Mutating maintenance controls are governed as separate canonical operations.</p>
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
