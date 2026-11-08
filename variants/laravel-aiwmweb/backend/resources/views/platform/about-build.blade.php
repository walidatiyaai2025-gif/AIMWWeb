@php
    $isArabic = app()->getLocale() === 'ar';
    $tr = fn (string $en, string $ar) => $isArabic ? $ar : $en;
    $tenantSlug = app(\App\Tenancy\TenantContext::class)->tenant()->slug;
    $shellHomeUrl = route('canonical.workspace.sites', ['tenant' => $tenantSlug], false);
    $shellTitle = $tr('About this build', 'حول هذا الإصدار');
    $shellScope = $tr('Workspace information', 'معلومات مساحة العمل');
    $shellLinks = [
        ['url' => $shellHomeUrl, 'label' => $tr('Sites', 'المواقع')],
        ['url' => '/tenants/'.rawurlencode($tenantSlug).'/about-build', 'label' => $shellTitle, 'active' => true],
        ['url' => '/tenants/'.rawurlencode($tenantSlug).'/release-notes', 'label' => $tr('Release notes', 'ملاحظات الإصدار')],
    ];
    $buildReportPayload = [
        'assemblyName' => $build['assemblyName'],
        'version' => $build['version'],
        'informationalVersion' => $build['informationalVersion'],
        'branch' => $build['branch'],
        'commit' => $build['commit'],
        'buildTimeUtc' => $build['buildTimeUtc'],
        'currentRelease' => $currentRelease ? [
            'title' => $currentRelease['title'] ?? '',
            'changes' => $currentRelease['changes'] ?? [],
        ] : null,
    ];
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shellTitle }} — AIWMWeb</title>
    <meta name="robots" content="noindex,nofollow">
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css', 'resources/js/about-build-copy-report.ts'])
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
    <style data-about-build-shell>
        .about-build-content :is(dl, pre, code) { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
        .about-build-content dl { display: grid; grid-template-columns: minmax(110px, 190px) minmax(0, 1fr); gap: 8px 16px; }
        .about-build-content dt { color: #a7b2c1; }
        .about-build-content dd { margin: 0; direction: ltr; text-align: start; }
        .about-build-content pre { white-space: pre-wrap; overflow-x: auto; padding: 14px; border: 1px solid #30394c; border-radius: 10px; }
        .about-build-content button { min-height: 44px; padding: 10px 16px; border: 1px solid #c5a45d; border-radius: 9px; background: #c5a45d; color: #19170f; font-weight: 700; cursor: pointer; }
        .about-build-content [hidden] { display: none !important; }
        @media (max-width: 600px) { .about-build-content dl { grid-template-columns: minmax(0, 1fr); gap: 4px; } .about-build-content dd { margin-bottom: 8px; } .about-build-content button { width: 100%; } }
    </style>
</head>
<body>
<div class="app-shell workspace-read-shell about-build-dashboard" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#about-build-content">{{ $tr('Skip to content', 'تخطَّ إلى المحتوى') }}</a>
    @include('layouts.partials.workspace-read-sidebar')
    <div class="main-area">
        @include('layouts.partials.workspace-read-topbar')
    <main id="about-build-content" class="content workspace-read-content about-build-content">
        <h1>{{ $shellTitle }}</h1>
        <p>{{ $tr('Verify the deployed build and review what shipped in the current release.', 'تحقق من الإصدار المثبت وراجع التغييرات التي تضمنها الإصدار الحالي.') }}</p>
        @if ($buildApiUrl)
            <p><a href="{{ $buildApiUrl }}" target="_blank" rel="noopener noreferrer" data-canonical-operation="AIMW-CONT-EBD53650BC">{{ $tr('Open build API', 'فتح واجهة بيانات الإصدار') }}</a></p>
        @endif

        <section aria-labelledby="build-actions">
            <h2 id="build-actions">{{ $tr('Build actions', 'إجراءات الإصدار') }}</h2>
            <button type="button" data-copy-build-report data-canonical-operation="AIMW-SYNC-68B372C9FE" aria-busy="false">{{ $tr('Copy build report', 'نسخ تقرير الإصدار') }}</button>
            <p data-copy-build-success role="status" hidden>{{ $tr('Build report copied. The browser confirmed that the report was written to the clipboard.', 'تم نسخ التقرير بعد تأكيد المتصفح إتمام النسخ.') }}</p>
            <div data-copy-build-error role="alert" hidden>
                <p data-copy-build-error-message>{{ $tr('The browser did not confirm a clipboard write. No copy success was reported; you can retry.', 'لم يؤكد المتصفح نسخ التقرير. لم يتم الإبلاغ عن نجاح العملية؛ يمكنك إعادة المحاولة.') }}</p>
                <button type="button" data-copy-build-retry>{{ $tr('Retry copy', 'إعادة محاولة النسخ') }}</button>
            </div>
        </section>

        <script id="build-report-payload" type="application/json">@json($buildReportPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>

        <section aria-labelledby="build-information">
            <h2 id="build-information">{{ $tr('Build information', 'معلومات الإصدار') }}</h2>
            <dl>
                <dt>{{ $tr('Application', 'التطبيق') }}</dt><dd>{{ $build['assemblyName'] ?: '—' }}</dd>
                <dt>{{ $tr('Version', 'الإصدار') }}</dt><dd>{{ $build['version'] ?: '—' }}</dd>
                <dt>{{ $tr('Informational version', 'الإصدار التفصيلي') }}</dt><dd>{{ $build['informationalVersion'] ?: '—' }}</dd>
                <dt>{{ $tr('Branch', 'الفرع') }}</dt><dd>{{ $build['branch'] ?: '—' }}</dd>
                <dt>{{ $tr('Commit SHA', 'بصمة Commit') }}</dt><dd>{{ $build['commit'] ?: '—' }}</dd>
                <dt>{{ $tr('Build time UTC', 'وقت البناء UTC') }}</dt><dd>{{ $build['buildTimeUtc'] ?: '—' }}</dd>
            </dl>
        </section>

        <section aria-labelledby="current-release">
            <h2 id="current-release">{{ $tr('Current release', 'الإصدار الحالي') }}</h2>
            @if ($currentRelease)
                <h3>{{ $currentRelease['title'] }}</h3>
                <pre>{{ $currentRelease['content'] }}</pre>
            @else
                <p>{{ $tr('No release notes were found.', 'لم يُعثر على ملاحظات للإصدار.') }}</p>
            @endif
        </section>

        @if (count($releases) > 1)
            <section aria-labelledby="release-history">
                <h2 id="release-history">{{ $tr('Release history', 'سجل الإصدارات') }}</h2>
                @foreach (array_slice($releases, 1) as $release)
                    <article>
                        <h3>{{ $release['title'] }}</h3>
                        @if (! empty($release['date']))<p>{{ $release['date'] }}</p>@endif
                        <pre>{{ $release['content'] }}</pre>
                    </article>
                @endforeach
            </section>
        @endif

        <section aria-labelledby="technical-details">
            <h2 id="technical-details">{{ $tr('Technical details', 'التفاصيل الفنية') }}</h2>
            <dl>
                <dt>{{ $tr('Runtime', 'بيئة التشغيل') }}</dt><dd>{{ $runtime }}</dd>
                <dt>{{ $tr('Framework', 'إطار العمل') }}</dt><dd>{{ $framework }}</dd>
                <dt>{{ $tr('Operating system', 'نظام التشغيل') }}</dt><dd>{{ $operatingSystem }}</dd>
                <dt>{{ $tr('Git commit', 'بصمة Git') }}</dt><dd>{{ $build['commit'] ?: '—' }}</dd>
                <dt>{{ $tr('API endpoint', 'مسار API') }}</dt>
                <dd>
                    @if ($buildApiUrl)
                        <a href="{{ $buildApiUrl }}">/api/build</a>
                    @else
                        <span>{{ $tr('/api/build (permission required)', '/api/build (يتطلب صلاحية)') }}</span>
                    @endif
                </dd>
            </dl>
        </section>
    </main>
    </div>
</div>
</body>
</html>
