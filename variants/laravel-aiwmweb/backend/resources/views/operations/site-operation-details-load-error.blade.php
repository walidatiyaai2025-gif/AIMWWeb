@php
    $isArabic = app()->getLocale() === 'ar';
    $tr = fn (string $en, string $ar): string => $isArabic ? $ar : $en;
    $tenantSlug = app(\App\Tenancy\TenantContext::class)->tenant()->slug;
    $shellHomeUrl = $historyUrl;
    $shellTitle = $tr('Operation details could not be loaded', 'تعذر تحميل تفاصيل العملية');
    $shellScope = $tr('Site operations', 'عمليات الموقع');
    $shellLinks = [
        ['url' => $historyUrl, 'label' => $tr('Site operations', 'عمليات الموقع')],
        ['url' => $retryUrl, 'label' => $shellTitle, 'active' => true],
    ];
    
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $shellTitle }} — AIWMWeb</title>
    <meta name="robots" content="noindex,nofollow">
    @vite(['resources/css/app.css'])
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
    <main id="site-operation-details-content" class="content workspace-read-content workspace-main" data-canonical-load-operation="{{ \App\Http\Controllers\SiteOperationDetailsReadController::LOAD_OPERATION_ID }}">
        <section class="panel" style="padding:28px">
            <h1>{{ $shellTitle }}</h1>
            <p>{{ $tr('The operation could not be read from retained history. The storage failure has not been presented as an operation-not-found result.', 'تعذر قراءة العملية من السجل المحفوظ بسبب خطأ في التخزين؛ هذا ليس دليلاً على أن العملية غير موجودة.') }}</p>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn primary" data-load-operation-retry data-canonical-operation="{{ \App\Http\Controllers\SiteOperationDetailsReadController::LOAD_OPERATION_ID }}" href="{{ $retryUrl }}">{{ $tr('Retry', 'إعادة المحاولة') }}</a>
                <a class="btn" href="{{ $historyUrl }}">{{ $tr('Back to history', 'العودة إلى السجل') }}</a>
            </div>
        </section>
    </main>
    </div>
</div>
</body>
</html>
