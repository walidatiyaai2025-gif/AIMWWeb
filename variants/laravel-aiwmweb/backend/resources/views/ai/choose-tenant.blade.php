@php
    // The selector is intentionally tenant-neutral: only the controller's authorized
    // active memberships may become destinations. Do not invent a tenant sidebar.
    $isArabic = session('public_welcome_locale') === 'ar';
    $pageTitle = $isArabic ? 'اختر مساحة العمل' : 'Choose a workspace';
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $pageTitle }} — AIWMWeb</title>
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Tajawal:wght@400;500;700;800&amp;display=swap">
    <style data-workspace-selector-shell>
        :root { color-scheme: dark; font-family: Inter, Tajawal, system-ui, sans-serif; }
        html:lang(ar) { font-family: Tajawal, Inter, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; background: #0b0f17; color: #f4f6fa; }
        .selector-shell { min-height: 100vh; display: grid; grid-template-rows: auto 1fr; }
        .selector-topbar { display: flex; align-items: center; gap: 12px; padding: 16px clamp(16px, 4vw, 48px); background: #151923; border-bottom: 1px solid #30394c; }
        .selector-brand-mark { display: inline-grid; place-items: center; border-radius: 10px; padding: 12px; background: #c5a45d; color: #19170f; font-weight: 800; }
        .selector-brand { color: #f4f6fa; font-weight: 750; text-decoration: none; }
        .selector-main { width: min(100% - 32px, 760px); margin: clamp(28px, 8vh, 76px) auto; }
        .selector-panel { padding: clamp(20px, 4vw, 36px); border: 1px solid #30394c; border-radius: 18px; background: #151923; }
        .selector-panel h1 { font-size: clamp(24px, 3vw, 34px); line-height: 1.3; margin: 0 0 12px; }
        .selector-panel p { line-height: 1.75; color: #b8c2d2; margin: 0 0 24px; }
        .selector-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 14px; }
        .selector-list li { min-width: 0; }
        .selector-list a { display: flex; align-items: center; min-height: 56px; padding: 14px 18px; border: 1px solid #39465a; border-radius: 12px; background: #1b2130; color: #f4f6fa; text-decoration: none; font-weight: 700; overflow-wrap: anywhere; }
        .selector-list a:hover { border-color: #c5a45d; background: #30354a; }
        :focus-visible { outline: 3px solid #e0bf73; outline-offset: 3px; }
        @media (max-width: 700px) { .selector-topbar { padding: 12px 16px; } .selector-main { margin-block: 24px; } .selector-list { grid-template-columns: minmax(0, 1fr); } }
    </style>
</head>
<body>
<div class="selector-shell" data-dashboard-shell="aiwmweb" data-tenant-neutral="true">
    <header class="selector-topbar">
        <span class="selector-brand-mark" aria-hidden="true">AI</span>
        <span class="selector-brand">AI WordPress Manager</span>
    </header>
    <main id="workspace-selector-content" class="selector-main">
        <section class="selector-panel" aria-labelledby="workspace-selector-heading">
            <h1 id="workspace-selector-heading">{{ $pageTitle }}</h1>
            <p>{{ $isArabic ? 'لديك أكثر من حساب نشط. اختر الحساب الذي تريد فتح لوحة تحكمه.' : 'You have more than one active account. Select the workspace you want to open.' }}</p>
            <nav aria-label="{{ $isArabic ? 'مساحات العمل المتاحة' : 'Available workspaces' }}">
                <ul class="selector-list">
                    @foreach ($tenants as $tenant)
                        <li><a href="/tenants/{{ rawurlencode((string) $tenant->slug) }}">{{ $tenant->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </section>
    </main>
</div>
</body>
</html>
