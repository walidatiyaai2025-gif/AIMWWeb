@php($isArabic = app()->getLocale() === 'ar')
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isArabic ? 'تدقيق الأمان' : 'Security Audit' }} — AIWMWeb</title>
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
        @media (min-width: 1140px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
            /* Three-column forms, plan cards and history records; full-width actions retained. */
        @media (min-width: 1140px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
        main form:has(> label ~ label ~ label) > :not(label), main section:has(> article ~ article) > :not(article), main section:has(> .record ~ .record) > :not(.record) { grid-column: 1 / -1; }
        main :is(.grid, .cards, .record-grid) > * { min-width: 0; }
        body { margin: 0; background: #0b0f17; color: #f4f6fa; }
        .security-audit-shell { --bg: #0b0f17; --surface: #151923; --border: #30394c; --text: #f4f6fa; --accent: #c5a45d; display: grid; grid-template-columns: minmax(220px, 284px) minmax(0, 1fr); min-height: 100vh; }
        .security-audit-shell, .security-audit-shell * { box-sizing: border-box; }
        .security-audit-shell .sidebar { position: sticky; top: 0; height: 100vh; overflow-y: auto; background: var(--surface); border-inline-end: 1px solid var(--border); padding: 20px; display: flex; flex-direction: column; gap: 18px; }
        .security-audit-shell .brand { display: flex; gap: 12px; align-items: center; color: var(--text); text-decoration: none; font-weight: 800; }
        .security-audit-shell .brand-mark { background: var(--accent); color: #14100b; padding: 10px; border-radius: 10px; }
        .security-audit-shell .main-nav { display: grid; gap: 8px; }
        .security-audit-shell .main-nav a { color: var(--text); text-decoration: none; padding: 12px; min-height: 44px; border-radius: 10px; }
        .security-audit-shell .main-nav a.active, .security-audit-shell .main-nav a:hover { background: rgba(197,164,93,.16); color: #e0bf73; }
        .security-audit-shell .main-area { min-width: 0; }
        .security-audit-shell .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
        .security-audit-shell main { max-width: 1150px; padding: 24px; margin: auto; }
        .security-audit-shell main > section { background: var(--surface); border: 1px solid var(--border); padding: 20px; border-radius: 16px; margin-bottom: 16px; }
        .security-audit-shell select, .security-audit-shell input { background: #1b2130; color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 10px; }
        .security-audit-shell button { background: var(--accent); color: #14100b; border: 0; border-radius: 8px; padding: 10px 14px; cursor: pointer; }
        .security-audit-shell a { color: #e0bf73; }
        .security-audit-pages { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 16px; }
        .security-audit-pages a, .security-audit-pages span { display: inline-flex; min-height: 44px; min-width: 44px; align-items: center; justify-content: center; border-radius: 8px; padding: 8px; border: 1px solid var(--border); text-decoration: none; }
        .security-audit-pages [aria-current="page"] { color: #14100b; background: var(--accent); }
        .security-audit-pages [aria-disabled="true"] { opacity: .5; }
        .security-audit-pages a:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
        @media (max-width: 700px) { .security-audit-shell { grid-template-columns: minmax(0, 1fr); } .security-audit-shell .sidebar { position: static; height: auto; padding: 12px; } .security-audit-shell .main-nav { grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); } .security-audit-shell .topbar, .security-audit-shell main { padding: 12px; } }
</style>
</head>
<body>
<div class="app-shell security-audit-shell" data-dashboard-shell="aiwmweb">
    <aside class="sidebar" aria-label="{{ $isArabic ? 'القائمة الرئيسية' : 'Main navigation' }}">
        <a class="brand" href="/admin"><span class="brand-mark">AI</span><span>AIWMWeb</span></a>
        <nav class="main-nav" aria-label="{{ $isArabic ? 'الأقسام' : 'Sections' }}">
            <a href="/admin">{{ $isArabic ? 'لوحة الإدارة' : 'Dashboard' }}</a>
            <a href="/admin/security-audit" class="active" aria-current="page">{{ $isArabic ? 'تدقيق الأمان' : 'Security Audit' }}</a>
            @if ($settingsHref !== null)
                <a href="{{ $settingsHref }}">{{ $isArabic ? 'الإعدادات' : 'Settings' }}</a>
            @endif
        </nav>
    </aside>
    <div class="main-area">
        <header class="topbar"><strong>{{ $isArabic ? 'تدقيق الأمان' : 'Security Audit' }}</strong><span>AIWMWeb</span></header>
<main>
    <section>
        <p>SECURITY</p>
        <h1>{{ $isArabic ? 'تدقيق الأمان' : 'Security Audit' }}</h1>
        <p>{{ $isArabic ? 'سجل أحداث الأمان للقراءة فقط ضمن نطاق المستأجر.' : 'Read-only security events from tenant-owned audit data.' }}</p>
        <p data-audit-safe-metadata-note>{{ $isArabic ? 'تُعرض حقول التشخيص المسموح بها فقط؛ تُحجب بيانات الاعتماد والاستجابات الخام والحقول غير المعروفة.' : 'Only allowlisted diagnostic fields are displayed. Credentials, raw provider errors and unknown metadata are never shown.' }}</p>
        @if ($settingsHref !== null)
            <a
                href="{{ $settingsHref }}"
                data-canonical-operation="{{ $settingsNavigationOperationId }}"
            >{{ $isArabic ? 'العودة إلى الإعدادات' : 'Back to settings' }}</a>
        @endif
    </section>

    <section>
        <h2>{{ $isArabic ? 'تصفية الأحداث' : 'Filter events' }}</h2>
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
            <label>
                <span>{{ $isArabic ? 'عدد السجلات في الصفحة' : 'Page size' }}</span>
                <select name="per_page" aria-label="{{ $isArabic ? 'عدد السجلات في الصفحة' : 'Audit page size' }}">
                    @foreach ([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                    @endforeach
                </select>
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
            <h2>{{ $isArabic ? 'أحدث أحداث الأمان' : 'Latest security events' }}</h2>
            <span data-audit-total="{{ $isFiltered ? 'unavailable' : $total }}">{{ $isFiltered ? $events->count() : $total }}</span>
        </header>

        @if ($isFiltered)
            <p role="status" data-audit-filter-limit>{{ $isArabic ? 'البحث والتصفية يفحصان آخر ١٠٠٠ حدث فقط حاليًا. قد توجد نتائج أقدم غير معروضة؛ العدد ليس إجمالي السجلات المطابقة.' : 'Filtered results currently scan the latest 1,000 events only. Older matches may exist; this is not a total matching count.' }}</p>
        @else
            <p role="status" data-audit-range>{{ $total === 0 ? 0 : (($page - 1) * $perPage + 1) }}–{{ min($total, $page * $perPage) }} {{ $isArabic ? 'من' : 'of' }} {{ $total }} · {{ $isArabic ? 'الصفحة' : 'Page' }} {{ $page }} / {{ $lastPage }}</p>
        @endif
        @if ($events->isEmpty())
            <p>{{ $isArabic ? 'لا توجد أحداث أمان مطابقة.' : 'No matching security events.' }}</p>
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
        @if (! $isFiltered && $lastPage > 1)
            @php($pagesStart = max(1, min($page - 2, $lastPage - 4)))
            @php($pagesEnd = min($lastPage, $pagesStart + 4))
            <nav class="security-audit-pages" aria-label="{{ $isArabic ? 'صفحات سجل الأمان' : 'Security audit pages' }}">
                @if ($page > 1)
                    <a href="{{ request()->fullUrlWithQuery(['page' => 1, 'per_page' => $perPage]) }}">{{ $isArabic ? 'الأولى' : 'First' }}</a>
                    <a href="{{ request()->fullUrlWithQuery(['page' => $page - 1, 'per_page' => $perPage]) }}">{{ $isArabic ? 'السابقة' : 'Previous' }}</a>
                @else
                    <span aria-disabled="true">{{ $isArabic ? 'الأولى' : 'First' }}</span>
                    <span aria-disabled="true">{{ $isArabic ? 'السابقة' : 'Previous' }}</span>
                @endif
                @for ($number = $pagesStart; $number <= $pagesEnd; $number++)
                    @if ($number === $page)
                        <span aria-current="page">{{ $number }}</span>
                    @else
                        <a href="{{ request()->fullUrlWithQuery(['page' => $number, 'per_page' => $perPage]) }}">{{ $number }}</a>
                    @endif
                @endfor
                @if ($page < $lastPage)
                    <a href="{{ request()->fullUrlWithQuery(['page' => $page + 1, 'per_page' => $perPage]) }}">{{ $isArabic ? 'التالية' : 'Next' }}</a>
                    <a href="{{ request()->fullUrlWithQuery(['page' => $lastPage, 'per_page' => $perPage]) }}">{{ $isArabic ? 'الأخيرة' : 'Last' }}</a>
                @else
                    <span aria-disabled="true">{{ $isArabic ? 'التالية' : 'Next' }}</span>
                    <span aria-disabled="true">{{ $isArabic ? 'الأخيرة' : 'Last' }}</span>
                @endif
            </nav>
        @endif
    </section>
</main>
    </div>
</div>
</body>
</html>
