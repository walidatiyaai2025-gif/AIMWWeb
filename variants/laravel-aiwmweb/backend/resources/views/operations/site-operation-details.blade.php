@php
    $isArabic = app()->getLocale() === 'ar';
    $tr = fn (string $en, string $ar): string => $isArabic ? $ar : $en;
    $tenantSlug = app(\App\Tenancy\TenantContext::class)->tenant()->slug;
    $shellHomeUrl = $historyUrl;
    $shellTitle = $tr('Site operation details', 'تفاصيل عملية الموقع');
    $shellScope = $tr('Site operations', 'عمليات الموقع');
    $shellLinks = [
        ['url' => $historyUrl, 'label' => $tr('Site operations', 'عمليات الموقع')],
        ['url' => route('canonical.workspace.site-operation-details', ['tenant' => $tenantSlug, 'operationId' => $operation->correlation_id], false), 'label' => $shellTitle, 'active' => true],
    ];
    if ($executionCenterUrl) {
        $shellLinks[] = ['url' => $executionCenterUrl, 'label' => $tr('Execution Center', 'مركز التنفيذ')];
    }
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shellTitle }} — AIWMWeb</title>
    <meta name="robots" content="noindex,nofollow">
    @vite(['resources/css/app.css', 'resources/js/site-operation-details-copy-report.ts'])
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
    <style data-site-operation-details-shell>
        .site-operation-details-shell .workspace-read-content :is(pre, code, dd) { overflow-wrap: anywhere; min-width: 0; }
        .site-operation-details-shell .workspace-read-content pre { white-space: pre-wrap; overflow-x: auto; padding: 14px; border: 1px solid #30394c; border-radius: 10px; }
        .site-operation-details-shell .workspace-read-content .contract-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr)); gap: 14px; }
        .site-operation-details-shell .workspace-read-content .contract-details > div { padding: 12px; background: #111927; border: 1px solid #30394c; border-radius: 10px; min-width: 0; }
        .site-operation-details-shell .workspace-read-content :is(button, .btn) { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 14px; border: 1px solid #a99368; border-radius: 9px; color: #f4f6fa; background: #243047; font-weight: 700; cursor: pointer; text-decoration: none; }
        .site-operation-details-shell .workspace-read-content .btn.primary { background: #c5a45d; color: #19170f; }
        .site-operation-details-shell .workspace-read-content [hidden] { display: none !important; }
        @media (max-width: 600px) { .site-operation-details-shell .workspace-read-content .d-flex { display: grid; grid-template-columns: minmax(0, 1fr); } .site-operation-details-shell .workspace-read-content :is(button, .btn) { width: 100%; } }
    </style>
</head>
<body>
<div class="app-shell workspace-read-shell site-operation-details-shell" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#site-operation-details-content">{{ $tr('Skip to content', 'تخطَّ إلى المحتوى') }}</a>
    @include('layouts.partials.workspace-read-sidebar')
    <div class="main-area">
        @include('layouts.partials.workspace-read-topbar')
    <main id="site-operation-details-content" class="content workspace-read-content workspace-main" data-canonical-operation="AIMW-AI-3CDB30A4C2" data-canonical-load-operation="{{ \App\Http\Controllers\SiteOperationDetailsReadController::LOAD_OPERATION_ID }}">
        <section class="hero-panel" aria-labelledby="site-operation-heading">
            <div>
                <span class="workspace-kicker">{{ $tr('SITE OPERATION', 'عملية الموقع') }}</span>
                <h1 id="site-operation-heading">{{ $shellTitle }}</h1>
                <p>{{ $tr('Read-only execution history for the active tenant.', 'سجل تنفيذ للقراءة فقط خاص بمساحة العمل الحالية.') }}</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn" data-canonical-operation="AIMW-AI-BC89B13AF8" href="{{ $historyUrl }}">{{ $tr('Back to site operations', 'العودة إلى عمليات الموقع') }}</a>
                @if ($executionCenterUrl)
                    <a
                        class="btn"
                        data-canonical-operation="AIMW-AI-98F705F888"
                        href="{{ $executionCenterUrl }}"
                    >{{ $tr('Execution Center', 'مركز التنفيذ') }}</a>
                @endif
            </div>
        </section>

        <section class="panel" aria-labelledby="operation-actions-heading">
            <h2 id="operation-actions-heading">{{ $tr('Operation actions', 'إجراءات العملية') }}</h2>
            <button
                type="button"
                data-copy-operation-report
                data-canonical-operation="AIMW-BILL-AA8C2FD5B3"
                aria-busy="false"
            >{{ $tr('Copy report', 'نسخ التقرير') }}</button>
            <p data-copy-operation-report-success role="status" hidden>{{ $tr('Report copied to the clipboard.', 'تم نسخ التقرير إلى الحافظة.') }}</p>
            <div data-copy-operation-report-error role="alert" hidden>
                <p data-copy-operation-report-error-message>{{ $tr('The report could not be written to the clipboard. No copy success was reported; check browser clipboard access and retry.', 'تعذر نسخ التقرير إلى الحافظة. لم يتم تأكيد النسخ؛ تحقق من صلاحيات الحافظة وحاول مجددًا.') }}</p>
                <button type="button" data-copy-operation-report-retry>{{ $tr('Retry copy', 'إعادة محاولة النسخ') }}</button>
            </div>
            <script id="site-operation-report-payload" type="application/json">@json($operationReport, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
        </section>

        <section class="panel" aria-labelledby="operation-summary-heading">
            <h2 id="operation-summary-heading">{{ $tr('Operation summary', 'ملخص العملية') }}</h2>
            <dl class="contract-details">
                <div><dt>{{ $tr('Result', 'النتيجة') }}</dt><dd>{{ $operation->status ?: '—' }}</dd></div>
                <div><dt>{{ $tr('Operation', 'العملية') }}</dt><dd>{{ $operation->operation ?: '—' }}</dd></div>
                <div><dt>{{ $tr('Site', 'الموقع') }}</dt><dd>{{ $site?->name ?: ('Site #'.(string) $operation->site_id) }}</dd></div>
                <div><dt>{{ $tr('Started', 'وقت البدء') }}</dt><dd>{{ $operation->started_at?->toIso8601String() ?: '—' }}</dd></div>
                <div><dt>{{ $tr('Completed', 'وقت الاكتمال') }}</dt><dd>{{ $operation->completed_at?->toIso8601String() ?: '—' }}</dd></div>
                <div><dt>{{ $tr('Duration', 'المدة') }}</dt><dd>{{ $durationMs === null ? '—' : $durationMs.' ms' }}</dd></div>
                <div><dt>{{ $tr('Affected records', 'السجلات المتأثرة') }}</dt><dd>{{ $operation->affected_records === null ? '—' : $operation->affected_records }}</dd></div>
                <div><dt>{{ $tr('Correlation ID', 'معرّف التتبع') }}</dt><dd><code>{{ $operation->correlation_id }}</code></dd></div>
            </dl>
        </section>

        <section class="panel" aria-labelledby="operation-message-heading">
            <h2 id="operation-message-heading">{{ $tr('Message', 'الرسالة') }}</h2>
            <p>{{ $operation->message ?: $tr('No message was recorded.', 'لم تُسجل رسالة.') }}</p>
        </section>

        <section class="panel" aria-labelledby="technical-details-heading">
            <h2 id="technical-details-heading">{{ $tr('Technical details', 'التفاصيل الفنية') }}</h2>
            @if (! empty($operation->details))
                <pre>{{ json_encode($operation->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            @else
                <p>{{ $tr('No technical details were recorded.', 'لم تُسجل تفاصيل فنية.') }}</p>
            @endif
        </section>
    </main>
    </div>
</div>
</body>
</html>
