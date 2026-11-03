<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security Audit</title>
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
<main>
    <section>
        <p>SECURITY</p>
        <h1>Security Audit</h1>
        <p>Read-only security events from tenant-owned audit data.</p>
        @if ($settingsHref !== null)
            <a
                href="{{ $settingsHref }}"
                data-canonical-operation="{{ $settingsNavigationOperationId }}"
            >Back to settings</a>
        @endif
    </section>

    <section>
        <h2>Filter events</h2>
        <form method="GET" action="/admin/security-audit">
            <label>
                <span>Category</span>
                <select name="category" aria-label="Filter audit category">
                    <option value="">All categories</option>
                    @foreach (['Authentication', 'Account', 'Authorization', 'Session', 'Configuration'] as $value)
                        <option value="{{ $value }}" @selected($category === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Outcome</span>
                <select name="outcome" aria-label="Filter audit outcome">
                    <option value="">All outcomes</option>
                    @foreach (['Succeeded', 'Failed', 'Blocked'] as $value)
                        <option value="{{ $value }}" @selected($outcome === $value)>{{ $value }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>Search</span>
                <input type="search" name="q" value="{{ $search }}" maxlength="200" aria-label="Search security audit trail">
            </label>
            <button type="submit" data-canonical-operation="AIMW-BILL-A152D6A7DE">Apply</button>
            <a
                href="/admin/security-audit"
                data-canonical-operation="AIMW-SYNC-AA26ED0909"
                aria-label="Clear audit filters and reload security events"
            >Clear</a>
        </form>
    </section>

    <section>
        <header>
            <h2>Latest security events</h2>
            <span>{{ $events->count() }}</span>
        </header>

        @if ($events->isEmpty())
            <p>No matching security events.</p>
        @else
            @foreach ($events as $event)
                <article>
                    <strong>{{ $event['event'] }}</strong>
                    <span>{{ $event['category'] }}</span>
                    <span>{{ $event['outcome'] }}</span>
                    <div>Tenant: {{ $event['tenant'] }}</div>
                    <div>Actor: {{ $event['actor'] }}</div>
                    <div>Target: {{ $event['target'] }}</div>
                    <div>Occurred: {{ $event['occurred_at'] }}</div>
                    @if ($event['metadata_text'] !== '')
                        <small>{{ $event['metadata_text'] }}</small>
                    @endif
                </article>
            @endforeach
        @endif
    </section>
</main>
</body>
</html>
