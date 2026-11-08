@php
    // Error fallback is tenant-neutral: translate presentation only, never inspect tenant secrets.
    $isArabic = request()->getPreferredLanguage(['ar', 'en']) === 'ar';
    $tr = fn (string $en, string $ar) => $isArabic ? $ar : $en;
    $errorDetailsPayload = [
        'errorId' => $errorId,
        'correlationId' => $correlationId,
    ];
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tr('Unexpected error', 'خطأ غير متوقع') }}</title>
    <meta name="robots" content="noindex,nofollow">
    @vite('resources/css/app.css')
    @vite('resources/js/error-copy-details.ts')
    <style>
        :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; background: #0b0f17; color: #f8fafc; }
        .error-page { min-height: 100vh; display: grid; place-items: center; padding: 32px; box-sizing: border-box; }
        .error-card { width: min(760px, 100%); padding: 32px; box-sizing: border-box; border: 1px solid rgba(248, 113, 113, .3); border-radius: 18px; background: rgba(255, 255, 255, .04); box-shadow: 0 18px 50px rgba(0, 0, 0, .28); }
        .kicker { display: inline-block; color: #f87171; font-size: 9.375px; font-weight: 900; letter-spacing: .14em; }
        h1 { margin: 10px 0; font-size: clamp(21.875px, 3.125vw, 32.8125px); }
        p { color: #cbd5e1; line-height: 1.65; }
        .diagnostics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 24px 0; }
        .diagnostic { padding: 14px; border: 1px solid rgba(148, 163, 184, .25); border-radius: 12px; background: rgba(15, 23, 42, .65); min-width: 0; }
        .diagnostic small { display: block; margin-bottom: 6px; color: #94a3b8; }
        .diagnostic strong { display: block; overflow-wrap: anywhere; }
        .note { padding: 14px; border-radius: 12px; background: rgba(59, 130, 246, .09); border: 1px solid rgba(96, 165, 250, .25); }
        .actions { margin-top: 24px; display: flex; gap: 10px; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; border: 0; border-radius: 10px; background: #2563eb; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #1e293b; border: 1px solid rgba(148, 163, 184, .25); }
        .button:disabled { cursor: wait; opacity: .65; }
        .button:focus-visible { outline: 3px solid #93c5fd; outline-offset: 3px; }
        @media (max-width: 480px) {
            .error-page { padding: 16px; }
            .error-card { padding: 20px 16px; }
            .actions .button { min-height: 44px; width: 100%; justify-content: center; }
        }
        .copy-status { margin: 12px 0 0; }
        .copy-error { margin-top: 12px; }
        [hidden] { display: none !important; }
    </style>
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
</head>
<body>
    <main class="error-page" role="main" aria-labelledby="error-title" data-canonical-operation="AIMW-CONT-455F01DAC7">
        <section class="error-card">
            <span class="kicker">{{ $tr('ERROR CENTER', 'مركز الأخطاء') }}</span>
            <h1 id="error-title">{{ $tr('An unexpected error occurred', 'حدث خطأ غير متوقع') }}</h1>
            <p>{{ $tr('The error was recorded. Use the tracking details below when reviewing logs or requesting support.', 'تم تسجيل الخطأ. استخدم معلومات التتبع أدناه عند مراجعة السجلات أو طلب الدعم.') }}</p>

            <h2>{{ $tr('Tracking information', 'معلومات التتبع') }}</h2>
            <p>{{ $tr('This screen does not expose sensitive technical details.', 'لا تعرض هذه الصفحة تفاصيل تقنية حساسة.') }}</p>

            <div class="diagnostics">
                <div class="diagnostic"><small>{{ $tr('Error ID', 'معرّف الخطأ') }}</small><strong>{{ $errorId }}</strong></div>
                <div class="diagnostic"><small>{{ $tr('Correlation ID', 'معرّف الارتباط') }}</small><strong>{{ $correlationId }}</strong></div>
                <div class="diagnostic"><small>{{ $tr('Error time', 'وقت الخطأ') }}</small><strong>{{ $errorTime }}</strong></div>
            </div>

            <div class="note">{{ $tr('Use the Error ID or Correlation ID when reviewing server logs or requesting support.', 'استخدم معرّف الخطأ أو الارتباط عند مراجعة سجلات الخادم أو التواصل مع الدعم.') }}</div>

            <div class="actions">
                <button class="button" type="button" data-copy-error-details data-canonical-operation="AIMW-SYNC-89777052CB" aria-busy="false">{{ $tr('Copy error details', 'نسخ تفاصيل الخطأ') }}</button>
                @if ($logsHref !== null)
                    <a class="button secondary" href="{{ $logsHref }}" data-canonical-operation="AIMW-CONT-8B3518EF80">{{ $tr('Open logs', 'فتح السجلات') }}</a>
                @endif
                <a class="button" href="/" data-canonical-operation="AIMW-CONT-85394A0E55">{{ $tr('Back to dashboard', 'العودة إلى لوحة التحكم') }}</a>
            </div>
            <p class="copy-status" data-copy-error-success role="status" hidden>{{ $tr('Error details copied. The browser confirmed the clipboard write.', 'تم نسخ تفاصيل الخطأ بعد تأكيد المتصفح إتمام النسخ.') }}</p>
            <div class="copy-error" data-copy-error-error role="alert" hidden>
                <p data-copy-error-error-message>{{ $tr('The browser did not confirm a clipboard write. No copy success was reported; you can retry.', 'لم يؤكد المتصفح نسخ النص. لم يتم الإبلاغ عن نجاح العملية؛ يمكنك المحاولة مجددًا.') }}</p>
                <button class="button" type="button" data-copy-error-retry>{{ $tr('Retry copy', 'إعادة محاولة النسخ') }}</button>
            </div>

            <script id="error-details-payload" type="application/json">@json($errorDetailsPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
        </section>
    </main>
</body>
</html>
