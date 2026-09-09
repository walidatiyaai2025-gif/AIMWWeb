<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Site Operation History Maintenance — AI WordPress Manager</title>
    @vite(['resources/css/app.css', 'resources/js/site-operations-maintenance-refresh.ts'])
</head>
<body>
<main class="fatal-error" data-canonical-operation="AIMW-AI-959B247B1D">
    <section class="panel">
        <span class="workspace-kicker">STORAGE MANAGEMENT</span>
        <h1>Site Operation History Maintenance</h1>
        <p>Review the real tenant-scoped operation-history footprint and the default retention preview. Maintenance mutations are separate canonical operations.</p>
        <p>
            <button class="btn"
                    type="button"
                    data-maintenance-refresh
                    data-refresh-url="{{ route('canonical.workspace.site-operations-maintenance.preview', ['tenant' => $tenant], false) }}"
                    data-canonical-operation="AIMW-AI-C5BC29CF27">Refresh preview</button>
            <span data-maintenance-refresh-status role="status" aria-live="polite">Maintenance preview is current.</span>
        </p>
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
            <dt>Total records</dt><dd data-testid="record-count" data-maintenance-field="record_count">{{ (int) $storage['record_count'] }}</dd>
            <dt>Sites represented</dt><dd data-maintenance-field="site_count">{{ (int) $storage['site_count'] }}</dd>
            <dt>Oldest operation</dt><dd data-maintenance-field="oldest_operation_at">{{ $storage['oldest_operation_at'] ?: '—' }}</dd>
            <dt>Newest operation</dt><dd data-maintenance-field="newest_operation_at">{{ $storage['newest_operation_at'] ?: '—' }}</dd>
            <dt>Storage</dt><dd data-maintenance-field="storage">{{ $storage['storage'] }}</dd>
        </dl>
    </section>

    <section class="panel" aria-label="Default cleanup preview">
        <h2>Default retention preview</h2>
        <p>90-day cutoff while retaining the newest 100 tenant-scoped records.</p>
        <dl>
            <dt>Eligible for removal</dt><dd data-maintenance-field="removable_count">{{ (int) $preview['removable_count'] }}</dd>
            <dt>Total in scope</dt><dd data-maintenance-field="total_count">{{ (int) $preview['total_count'] }}</dd>
            <dt>Keep latest</dt><dd data-maintenance-field="keep_latest">{{ (int) $preview['keep_latest'] }}</dd>
            <dt>Cutoff</dt><dd data-maintenance-field="cutoff">{{ $preview['cutoff'] }}</dd>
        </dl>
    </section>
</main>
</body>
</html>