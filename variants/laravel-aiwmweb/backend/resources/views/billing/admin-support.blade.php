@php
    $isArabic = app()->getLocale() === 'ar';
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isArabic ? 'دعم الفوترة' : 'Billing support' }} — AIWMWeb</title>
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
    @include('layouts.partials.workspace-read-styles')
</head>
<body>
@php
    $shellTitle = $isArabic ? 'دعم الفوترة' : 'Billing support';
    $shellScope = $isArabic ? 'إدارة المنصة' : 'Platform administration';
    $shellHomeUrl = route('canonical.admin.billing-support', [], false);
    $shellLinks = [
        ['url' => $shellHomeUrl, 'label' => $shellTitle, 'active' => true],
        ['url' => '/admin/security-audit', 'label' => $isArabic ? 'تدقيق الأمان' : 'Security audit'],
    ];
@endphp
<div class="app-shell workspace-read-shell" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#workspace-read-content">{{ $isArabic ? 'تخط إلى المحتوى' : 'Skip to content' }}</a>
    @include('layouts.partials.workspace-read-sidebar')
    <div class="main-area">
        @include('layouts.partials.workspace-read-topbar')
        <main id="workspace-read-content" class="content workspace-read-content" data-canonical-operation="{{ $canonicalOperationId }}">
        <header>
            <p>{{ $shellScope }}</p>
            <h1>{{ $shellTitle }}</h1>
            <p>{{ $isArabic ? 'مراجعة حالة الفوترة من حساب مسؤول المنصة المصرح له.' : 'Inspect and support account billing from an authenticated platform-administrator boundary.' }}</p>
        </header>

        <section aria-labelledby="billing-support-safety">
            <h2 id="billing-support-safety">{{ $isArabic ? 'سلامة دعم الفوترة' : 'Support safety' }}</h2>
            <p>{{ $isArabic ? 'تظل حالة الدفع معتمدة فقط إذا ثبتت بسجل فوترة نهائي أو لدى مزود الدفع المعتمد. إعادة توجيه المتصفح والمعرّفات المقدمة من المستخدم لا تثبت نجاح الدفع.' : 'Payment state is authoritative only when confirmed by committed billing evidence or the configured payment provider. Browser returns and caller-supplied identifiers are not treated as payment success.' }}</p>
            <p>{{ $isArabic ? 'هذه الصفحة للقراءة فقط. أوامر دعم الفوترة ونتائج تدقيقها عمليات منفصلة لا تنفّذ بمجرد فتح الصفحة.' : 'This route is read-only. Billing support commands and their audited success or failure states remain separately governed operations and are not executed by opening this page.' }}</p>
        </section>

        <section aria-labelledby="billing-support-scope">
            <h2 id="billing-support-scope">{{ $isArabic ? 'نطاق الوصول' : 'Access scope' }}</h2>
            <p>{{ $isArabic ? 'لا يقبل هذا المسار معرّفات حساب أو مستأجر أو اشتراك أو مزود دفع أو مستخدم. تُحدَّد صلاحية الوصول بهوية مسؤول المنصة الموثقة على الخادم.' : 'The route accepts no tenant, account, subscription, payment-provider, or user resource identifier. Access is derived from the authenticated server-side platform-administrator identity.' }}</p>
        </section>
        </main>
    </div>
</div>
</body>
</html>
