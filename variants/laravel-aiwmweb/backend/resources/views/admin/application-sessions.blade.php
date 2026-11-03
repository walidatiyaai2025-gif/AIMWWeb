<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Session Management') }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; background: Canvas; color: CanvasText; }
        main { max-width: 1060px; margin: 0 auto; padding: 28px 20px 48px; }
        .panel { border: 1px solid color-mix(in srgb, CanvasText 16%, transparent); border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        .hero { display: flex; justify-content: space-between; gap: 18px; align-items: start; }
        .kicker { font-size: 7.5px; letter-spacing: .12em; font-weight: 800; opacity: .7; }
        h1, h2 { margin: 6px 0 8px; }
        p { line-height: 1.55; }
        a { color: inherit; }
        .list { display: grid; gap: 12px; margin-top: 14px; }
        .row { border: 1px solid color-mix(in srgb, CanvasText 13%, transparent); border-radius: 10px; padding: 14px; }
        .row-head { display: flex; justify-content: space-between; gap: 14px; align-items: center; }
        .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 7px 14px; margin-top: 10px; font-size: 8.75px; opacity: .82; overflow-wrap: anywhere; }
        .badge { border: 1px solid color-mix(in srgb, CanvasText 20%, transparent); border-radius: 999px; padding: 4px 9px; font-size: 7.5px; white-space: nowrap; }
        .empty { padding: 14px; border-radius: 10px; background: color-mix(in srgb, CanvasText 6%, transparent); }
        small { display: block; opacity: .75; margin-top: 3px; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than previous baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 62.5%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }

        @media (min-width: 1140px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        }
        @media (min-width: 701px) and (max-width: 1139px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        }
        @media (max-width: 700px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
        }
            /* Three-column forms, plan cards and history records; full-width actions retained. */
        @media (min-width: 1140px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
        main form:has(> label ~ label ~ label) > :not(label), main section:has(> article ~ article) > :not(article), main section:has(> .record ~ .record) > :not(.record) { grid-column: 1 / -1; }
        main :is(.grid, .cards, .record-grid) > * { min-width: 0; }
</style>
</head>
<body>
<main data-canonical-operation="{{ $operationId }}">
    <section class="panel hero">
        <div>
            <span class="kicker">SECURITY SESSIONS</span>
            <h1>{{ __('Application sessions') }}</h1>
            <p>{{ __('Review active application sessions for accounts that belong to this tenant. Opening this page is read-only.') }}</p>
        </div>
        <a href="{{ route('canonical.alias.settings-sessions', ['tenant' => $tenant->slug], false) }}" data-canonical-operation="AIMW-CONT-F9A87DD98C">{{ __('My sessions') }}</a>
    </section>

    <section class="panel">
        <h2>{{ __('Active accounts') }}</h2>
        <p>{{ __('Accounts in this tenant that currently have at least one non-expired application session.') }}</p>

        @if ($activeAccounts->isEmpty())
            <div class="empty" data-empty-active-accounts>{{ __('No accounts currently have active sessions.') }}</div>
        @else
            <div class="list">
                @foreach ($activeAccounts as $account)
                    <article class="row">
                        <div class="row-head">
                            <div>
                                <strong>{{ $account['user_name'] }}</strong>
                                <small>{{ $account['role'] }} · {{ trans_choice(':count active session|:count active sessions', $account['active_sessions'], ['count' => $account['active_sessions']]) }}</small>
                            </div>
                            @if ($account['has_current_session'])
                                <span class="badge">{{ __('Your current account') }}</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="panel">
        <h2>{{ __('Session inventory') }}</h2>
        <p>{{ __('Last activity, account, role, device and network address for active sessions in this tenant.') }}</p>

        @if ($sessions->isEmpty())
            <div class="empty" data-empty-session-inventory>{{ __('No active sessions.') }}</div>
        @else
            <div class="list">
                @foreach ($sessions as $session)
                    <article class="row" data-session-row>
                        <div class="row-head">
                            <div>
                                <strong>{{ $session['user_name'] }}</strong>
                                <small>{{ $session['role'] }} · {{ $session['last_activity']->toIso8601String() }}</small>
                            </div>
                            <span class="badge">{{ $session['is_current'] ? __('Current session') : __('Active') }}</span>
                        </div>
                        <div class="meta">
                            <span><b>{{ __('Last activity') }}:</b> {{ $session['last_activity']->toIso8601String() }}</span>
                            <span><b>{{ __('Expires') }}:</b> {{ $session['expires_at']->toIso8601String() }}</span>
                            <span><b>IP:</b> {{ $session['ip_address'] ?? '—' }}</span>
                            <span><b>{{ __('Device') }}:</b> {{ $session['user_agent'] ?? '—' }}</span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</main>
</body>
</html>
