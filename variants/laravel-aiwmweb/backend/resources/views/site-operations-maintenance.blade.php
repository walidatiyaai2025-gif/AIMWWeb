<!DOCTYPE html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>Site Operation History Maintenance — AI WordPress Manager</title>
    @vite(['resources/css/app.css', 'resources/js/site-operations-maintenance-refresh.ts', 'resources/js/site-operations-maintenance-reload.ts'])
</head>
<body>
<main class="fatal-error" data-canonical-operation="AIMW-AI-959B247B1D">
    <section class="panel">
        <span class="workspace-kicker">STORAGE MANAGEMENT</span>
        <h1>Site Operation History Maintenance</h1>
        <p>Review the real tenant-scoped operation-history footprint and refresh the retention preview with the same policy choices as the authoritative source. Maintenance mutations are separate canonical operations.</p>
        <div style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
            <label>
                <span>Delete operations older than</span>
                <select class="form-control" data-maintenance-policy="older_than_days">
                    <option value="30">30 days</option>
                    <option value="60">60 days</option>
                    <option value="90" selected>90 days</option>
                    <option value="180">180 days</option>
                    <option value="365">365 days</option>
                </select>
            </label>
            <label>
                <span>Always keep the newest</span>
                <select class="form-control" data-maintenance-policy="keep_latest">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="250">250</option>
                    <option value="500">500</option>
                </select>
            </label>
            <button class="btn"
                    type="button"
                    data-maintenance-refresh
                    data-maintenance-refresh-control
                    data-refresh-url="{{ route('canonical.workspace.site-operations-maintenance.preview', ['tenant' => $tenant], false) }}"
                    data-canonical-operation="AIMW-AI-C5BC29CF27">Refresh preview</button>
        </div>
        <p><span data-maintenance-refresh-status role="status" aria-live="polite">Maintenance preview is current.</span></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn"
                    type="button"
                    data-maintenance-reload
                    data-maintenance-refresh-control
                    data-refresh-url="{{ route('canonical.workspace.site-operations-maintenance.reload', ['tenant' => $tenant], false) }}"
                    data-canonical-operation="AIMW-AI-CAAC427FC0">↻ Refresh</button>
            <a class="btn"
               data-canonical-operation="AIMW-AI-C2776A0F99"
               href="{{ route('canonical.workspace.site-operations', ['tenant' => $tenant], false) }}">Operation history</a>
            @if ($canOpenOperationsHub)
                <a class="btn primary"
                   data-canonical-operation="AIMW-AI-9E73ABE9CE"
                   href="{{ route('canonical.workspace.operations', ['tenant' => $tenant], false) }}">Operations hub</a>
            @endif
        </div>
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

    <section class="panel" aria-label="Current cleanup preview">
        <h2>Default retention preview</h2>
        <p><span data-maintenance-field="older_than_days">90</span>-day cutoff while retaining the newest <span data-maintenance-field="keep_latest">{{ (int) $preview['keep_latest'] }}</span> tenant-scoped records.</p>
        <dl>
            <dt>Eligible for removal</dt><dd data-maintenance-field="removable_count">{{ (int) $preview['removable_count'] }}</dd>
            <dt>Total in scope</dt><dd data-maintenance-field="total_count">{{ (int) $preview['total_count'] }}</dd>
            <dt>Cutoff</dt><dd data-maintenance-field="cutoff">{{ $preview['cutoff'] }}</dd>
        </dl>
    </section>
</main>
</body>
</html>
