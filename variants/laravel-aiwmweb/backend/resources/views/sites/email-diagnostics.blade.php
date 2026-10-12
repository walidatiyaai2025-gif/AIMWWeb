@php
    $isArabic = app()->getLocale() === 'ar';
    $configured = filled($profile['host'] ?? null) && filled($profile['from_address'] ?? null);
    $copy = $isArabic ? [
        'title' => 'تشخيص بريد الموقع',
        'subtitle' => 'راجع ملف SMTP المحفوظ لهذا الموقع بدون عرض أي كلمة مرور.',
        'back' => 'العودة لإعداد البريد',
        'site' => 'الموقع',
        'profile' => 'ملف SMTP',
        'mode' => 'الوضع',
        'inherited' => 'يرث إعداد الحساب',
        'site_profile' => 'إعداد خاص بالموقع',
        'host' => 'الخادم',
        'from' => 'عنوان الإرسال',
        'recipient' => 'مستلم الاختبار المقترح',
        'configured' => 'ملف SMTP محفوظ',
        'empty' => 'لا يوجد ملف SMTP محفوظ لهذا الموقع بعد.',
        'none' => 'لا يوجد مستلم مفعّل.',
    ] : [
        'title' => 'Site email diagnostics',
        'subtitle' => 'Inspect the saved SMTP profile for this site without exposing any password.',
        'back' => 'Back to email settings',
        'site' => 'Site',
        'profile' => 'SMTP profile',
        'mode' => 'Mode',
        'inherited' => 'Inherited account profile',
        'site_profile' => 'Site-specific profile',
        'host' => 'Host',
        'from' => 'From address',
        'recipient' => 'Suggested test recipient',
        'configured' => 'Saved SMTP profile',
        'empty' => 'No saved SMTP profile exists for this site yet.',
        'none' => 'No enabled recipient is configured.',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['title'] }}</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, system-ui, sans-serif; }
        body { margin: 0; background: Canvas; color: CanvasText; }
        main { width: min(920px, calc(100% - 32px)); margin: 40px auto; }
        header, section { border: 1px solid color-mix(in srgb, CanvasText 18%, transparent); border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        header { display: flex; gap: 20px; align-items: flex-start; justify-content: space-between; }
        h1, h2 { margin-top: 0; }
        .btn { display: inline-block; padding: 10px 14px; border: 1px solid color-mix(in srgb, CanvasText 28%, transparent); border-radius: 8px; color: inherit; text-decoration: none; font-weight: 700; white-space: nowrap; }
        dl { display: grid; grid-template-columns: minmax(140px, 220px) 1fr; gap: 10px 18px; margin: 0; }
        dt { font-weight: 700; }
        dd { margin: 0; overflow-wrap: anywhere; }
        .empty { padding: 14px; border-radius: 8px; background: color-mix(in srgb, CanvasText 6%, transparent); }
        @media (max-width: 640px) { main { margin-top: 20px; } header { display: grid; } dl { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <p>SMTP DIAGNOSTICS</p>
            <h1>{{ $copy['title'] }}</h1>
            <p>{{ $copy['subtitle'] }}</p>
        </div>
        <a
            class="btn"
            href="{{ route('canonical.workspace.site-email-settings.site', ['tenant' => $tenant, 'site' => $selectedSite->getKey()]) }}"
            data-canonical-operation="{{ $backOperationId }}"
        >{{ $copy['back'] }}</a>
    </header>

    <section aria-labelledby="site-diagnostics-heading">
        <h2 id="site-diagnostics-heading">{{ $copy['site'] }}: {{ $selectedSite->name }}</h2>

        @if ($configured)
            <dl>
                <dt>{{ $copy['profile'] }}</dt><dd>{{ $copy['configured'] }}</dd>
                <dt>{{ $copy['mode'] }}</dt><dd>{{ ($profile['use_account_profile'] ?? false) ? $copy['inherited'] : $copy['site_profile'] }}</dd>
                <dt>{{ $copy['host'] }}</dt><dd>{{ $profile['host'] }}</dd>
                <dt>{{ $copy['from'] }}</dt><dd>{{ $profile['from_address'] }}</dd>
                <dt>{{ $copy['recipient'] }}</dt><dd>{{ $suggestedRecipient?->email_address ?? $copy['none'] }}</dd>
            </dl>
        @else
            <p class="empty">{{ $copy['empty'] }}</p>
            <p>{{ $copy['recipient'] }}: {{ $suggestedRecipient?->email_address ?? $copy['none'] }}</p>
        @endif
    </section>
</main>
</body>
</html>
