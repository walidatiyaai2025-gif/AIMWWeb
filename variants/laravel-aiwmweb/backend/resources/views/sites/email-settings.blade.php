@php
    $isArabic = app()->getLocale() === 'ar';
    $copy = $isArabic ? [
        'title' => 'إعدادات البريد للموقع',
        'subtitle' => 'أضف مستلمين لإشعارات هذا الموقع. يتم تطبيق حد الخطة عند الحفظ.',
        'choose' => 'اختر موقعاً',
        'open' => 'فتح',
        'email' => 'عنوان البريد الإلكتروني',
        'name' => 'اسم العرض (اختياري)',
        'add' => 'إضافة المستلم',
        'recipients' => 'المستلمون الحاليون',
        'empty' => 'لا يوجد مستلمون لهذا الموقع.',
        'status' => 'الحالة',
        'enabled' => 'مفعّل',
        'back' => 'العودة إلى تفاصيل الموقع',
    ] : [
        'title' => 'Site email settings',
        'subtitle' => 'Add recipients for this site’s notifications. The account plan recipient limit is enforced when you save.',
        'choose' => 'Choose a site',
        'open' => 'Open',
        'email' => 'Email address',
        'name' => 'Display name (optional)',
        'add' => 'Add recipient',
        'recipients' => 'Current recipients',
        'empty' => 'No recipients are configured for this site.',
        'status' => 'Status',
        'enabled' => 'Enabled',
        'back' => 'Back to site details',
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
        main { width: min(960px, calc(100% - 32px)); margin: 40px auto; }
        header, section { border: 1px solid color-mix(in srgb, CanvasText 18%, transparent); border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        h1, h2 { margin-top: 0; }
        form { display: grid; gap: 12px; }
        label { display: grid; gap: 6px; font-weight: 600; }
        input, select, button { font: inherit; padding: 10px 12px; border-radius: 8px; border: 1px solid color-mix(in srgb, CanvasText 28%, transparent); }
        button { cursor: pointer; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .notice { padding: 12px; border-radius: 8px; margin-bottom: 14px; }
        .errors { border: 1px solid currentColor; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: start; border-bottom: 1px solid color-mix(in srgb, CanvasText 14%, transparent); }
        @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } main { margin-top: 20px; } }
    </style>
</head>
<body>
<main>
    <header>
        <p>Email / {{ $selectedSite?->name ?? $copy['choose'] }}</p>
        <h1>{{ $copy['title'] }}</h1>
        <p>{{ $copy['subtitle'] }}</p>
    </header>

    <section aria-labelledby="site-selection-heading">
        <h2 id="site-selection-heading">{{ $copy['choose'] }}</h2>
        <form method="get" action="{{ route('canonical.workspace.site-email-settings.module', ['tenant' => $tenant]) }}">
            <label>
                {{ $copy['choose'] }}
                <select name="site" required>
                    <option value="">—</option>
                    @foreach ($sites as $siteOption)
                        <option value="{{ $siteOption->getKey() }}" @selected($selectedSite?->getKey() === $siteOption->getKey())>{{ $siteOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">{{ $copy['open'] }}</button>
        </form>
    </section>

    @if ($selectedSite)
        <section aria-labelledby="add-recipient-heading">
            <h2 id="add-recipient-heading">{{ $copy['add'] }}</h2>

            @if (session('success'))
                <p class="notice" role="status">{{ session('success') }}</p>
            @endif
            @if ($errors->any())
                <div class="notice errors" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                method="post"
                action="{{ route('canonical.workspace.site-email-settings.recipient-add', ['tenant' => $tenant, 'site' => $selectedSite->getKey()]) }}"
                data-canonical-operation="AIMW-BILL-07134347F9"
                data-source-handler="AddAsync"
                onsubmit="this.querySelector('button[type=submit]').disabled = true"
            >
                @csrf
                <div class="grid">
                    <label>
                        {{ $copy['email'] }}
                        <input type="email" name="email_address" value="{{ old('email_address') }}" maxlength="320" autocomplete="email" required>
                    </label>
                    <label>
                        {{ $copy['name'] }}
                        <input type="text" name="display_name" value="{{ old('display_name') }}" maxlength="120" autocomplete="name">
                    </label>
                </div>
                <button type="submit" data-canonical-operation="AIMW-BILL-07134347F9">{{ $copy['add'] }}</button>
            </form>
        </section>

        <section aria-labelledby="recipient-list-heading">
            <h2 id="recipient-list-heading">{{ $copy['recipients'] }}</h2>
            @if ($recipients->isEmpty())
                <p>{{ $copy['empty'] }}</p>
            @else
                <div style="overflow-x:auto">
                    <table>
                        <thead><tr><th>{{ $copy['email'] }}</th><th>{{ $copy['name'] }}</th><th>{{ $copy['status'] }}</th></tr></thead>
                        <tbody>
                        @foreach ($recipients as $recipient)
                            <tr>
                                <td>{{ $recipient->email_address }}</td>
                                <td>{{ $recipient->display_name ?: '—' }}</td>
                                <td>{{ $recipient->is_enabled ? $copy['enabled'] : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p><a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $selectedSite->getKey() }}">{{ $copy['back'] }}</a></p>
        </section>
    @endif
</main>
</body>
</html>
