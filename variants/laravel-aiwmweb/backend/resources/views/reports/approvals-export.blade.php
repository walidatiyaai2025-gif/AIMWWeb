@php
    $isArabic = app()->getLocale() === 'ar';
    $reportTitle = $isArabic ? 'التقارير والتصدير' : 'Reports & Exports';
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reportTitle }} — AIWMWeb</title>
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
</style>
    @include('layouts.partials.workspace-read-styles')
    <style data-aiwm-reports-shell-styles>
        .workspace-read-shell .workspace-read-content .reports-table-scroll {
            overflow-x: auto; max-width: 100%; border-radius: 10px;
            border: 1px solid var(--border); margin-block: 14px;
        }
        .workspace-read-shell .reports-table-scroll table {
            width: 100%; min-width: 530px; border-collapse: collapse; text-align: start;
        }
        .workspace-read-shell .reports-table-scroll :is(th, td) {
            padding: 12px; text-align: start; border-bottom: 1px solid var(--border);
            overflow-wrap: anywhere;
        }
        .workspace-read-shell .reports-table-scroll th { background: var(--surface2); color: var(--text); }
        .workspace-read-shell .workspace-read-content section > header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
        }
        .workspace-read-shell .workspace-read-content section > header h2 { margin: 0; }
        .workspace-read-shell .workspace-read-content section > header a {
            padding: 9px 15px; border: 1px solid var(--border);
            border-radius: 10px; text-decoration: none;
        }
        .workspace-read-shell .workspace-read-content section > header span[aria-disabled] {
            color: var(--muted); font-size: 12px;
        }
        @media (max-width: 760px) {
            .workspace-read-shell .reports-table-scroll table { min-width: 490px; }
        }
    </style>
</head>
<body>
@php
    $shellTitle = $reportTitle;
    $shellScope = $activeTenant->name;
    $shellHomeUrl = route('canonical.alias.reports', ['tenant' => $activeTenant->slug], false);
    $shellLinks = [
        ['url' => $shellHomeUrl, 'label' => $reportTitle, 'active' => true],
        ['url' => '#report-sites', 'label' => $isArabic ? 'تقرير المواقع' : 'Sites report'],
        ['url' => '#report-planner', 'label' => $isArabic ? 'تقرير مخطط المحتوى' : 'Content planner report'],
        ['url' => '#report-approvals', 'label' => $isArabic ? 'تقرير الموافقات' : 'Approvals report'],
    ];
@endphp
<div class="app-shell workspace-read-shell" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#workspace-read-content">{{ $isArabic ? 'تخط إلى المحتوى' : 'Skip to content' }}</a>
    @include('layouts.partials.workspace-read-sidebar')
    <div class="main-area">
        @include('layouts.partials.workspace-read-topbar')
        <main id="workspace-read-content" class="content workspace-read-content">
    <section>
        <p>{{ $isArabic ? 'ذكاء الأعمال' : 'BUSINESS INTELLIGENCE' }}</p>
        <h1>{{ $reportTitle }}</h1>
        <p>{{ $isArabic ? 'تقارير مباشرة من بيانات التطبيق الفعلية.' : 'Live reports from real application data.' }}</p>
        <a
            data-canonical-operation="AIMW-BILL-A6E9CF63BC"
            href="{{ request()->getRequestUri() }}"
            aria-label="{{ $isArabic ? 'تحديث التقارير' : 'Refresh reports' }}"
        >{{ $isArabic ? 'تحديث' : 'Refresh' }}</a>
    </section>

    <section id="report-sites" data-canonical-operation="AIMW-SYNC-D8581471A2">
        <header>
            <h2>{{ $isArabic ? 'تقرير المواقع' : 'Sites report' }}</h2>
            @if ($canExport)
                <a href="{{ $sitesDownloadUrl }}" download="sites-report.csv">CSV</a>
            @else
                <span aria-disabled="true">{{ $isArabic ? 'CSV — يلزم إذن reports.manage' : 'CSV — reports.manage required' }}</span>
            @endif
        </header>

        @if ($siteRows->isEmpty())
            <p>{{ $isArabic ? 'لا توجد سجلات مواقع لهذا الحساب.' : 'No site rows are available for this tenant.' }}</p>
        @else
            <div class="reports-table-scroll" role="region" aria-label="{{ $isArabic ? 'جدول التقرير' : 'Report table' }}" tabindex="0">
            <table>
                <thead>
                <tr><th>{{ $isArabic ? 'الاسم' : 'Name' }}</th><th>{{ $isArabic ? 'الرابط' : 'URL' }}</th><th>{{ $isArabic ? 'الحالة' : 'Status' }}</th></tr>
                </thead>
                <tbody>
                @foreach ($siteRows->take(12) as $site)
                    <tr>
                        <td>{{ $site['name'] }}</td>
                        <td>{{ $site['url'] }}</td>
                        <td>{{ $site['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </section>

    <section id="report-planner" data-canonical-operation="AIMW-BILL-5E76AD4FAE">
        <header>
            <h2>{{ $isArabic ? 'تقرير مخطط المحتوى' : 'Content planner report' }}</h2>
            @if ($canExport)
                <a href="{{ $plannerDownloadUrl }}" download="content-planner-report.csv">CSV</a>
            @else
                <span aria-disabled="true">{{ $isArabic ? 'CSV — يلزم إذن reports.manage' : 'CSV — reports.manage required' }}</span>
            @endif
        </header>

        @if ($plannerRows->isEmpty())
            <p>{{ $isArabic ? 'لا توجد سجلات في مخطط المحتوى لهذا الحساب.' : 'No content planner rows are available for this tenant.' }}</p>
        @else
            <div class="reports-table-scroll" role="region" aria-label="{{ $isArabic ? 'جدول التقرير' : 'Report table' }}" tabindex="0">
            <table>
                <thead>
                <tr><th>{{ $isArabic ? 'العنوان' : 'Title' }}</th><th>{{ $isArabic ? 'الموقع' : 'Site' }}</th><th>{{ $isArabic ? 'الحالة' : 'Status' }}</th></tr>
                </thead>
                <tbody>
                @foreach ($plannerRows->take(12) as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['site'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </section>

    <section id="report-approvals" data-canonical-operation="AIMW-APPR-A8F5FB3762">
        <header>
            <h2>{{ $isArabic ? 'تقرير الموافقات' : 'Approvals report' }}</h2>
            @if ($canExport)
                <a href="{{ $downloadUrl }}" download="approvals-report.csv">CSV</a>
            @else
                <span aria-disabled="true">{{ $isArabic ? 'CSV — يلزم إذن reports.manage' : 'CSV — reports.manage required' }}</span>
            @endif
        </header>

        @if ($rows->isEmpty())
            <p>{{ $isArabic ? 'لا توجد سجلات موافقات لهذا الحساب.' : 'No approval rows are available for this tenant.' }}</p>
        @else
            <div class="reports-table-scroll" role="region" aria-label="{{ $isArabic ? 'جدول التقرير' : 'Report table' }}" tabindex="0">
            <table>
                <thead>
                <tr><th>{{ $isArabic ? 'العنوان' : 'Title' }}</th><th>{{ $isArabic ? 'الموقع' : 'Site' }}</th><th>{{ $isArabic ? 'الحالة' : 'Status' }}</th></tr>
                </thead>
                <tbody>
                @foreach ($rows->take(12) as $row)
                    <tr>
                        <td>{{ $row['title'] }}</td>
                        <td>{{ $row['site'] }}</td>
                        <td>{{ $row['status'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </section>
        </main>
    </div>
</div>
</body>
</html>
